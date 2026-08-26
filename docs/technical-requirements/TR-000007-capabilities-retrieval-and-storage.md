# TR-000007: Capabilities Retrieval and Storage

## Related Functional Requirements

- [FR-000008 — Carrier capabilities and contract definitions](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md)
- [FR-000010 — Graceful degradation on capability changes](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md)
- [FR-000009 — Insurance as a range](../functional-requirements/FR-000009-insurance-as-a-range.md)

## Related ADRs

- None.

## Category

Performance / Reliability

## Requirement

Capability data must be retrieved per MyParcel account, cached so that no user-facing request makes an uncached call it could avoid, and consumed defensively so that a change in the response cannot break the module.

Two classes of data with different lifecycles are stored differently: **contract definitions** (account-level, no shipment context) are fetched and persisted at account refresh; **shipment capabilities** (dependent on the specific shipment) are cached lazily on a hash of the request.

## Rationale

These questions are currently answered in-process with **zero I/O** — `canHaveShipmentOption()` was pure logic on an SDK consignment class. Moving them to an HTTP endpoint puts a network call on two hot paths: checkout delivery options, and the admin *New Shipment* form, which probes once per carrier on every render. Uncached, that is a straight latency regression for every merchant and a new dependency for checkout.

The storage split is not an implementation preference. Contract definitions are bounded and enumerable, so they can be fetched once at a known moment and stored like other account data. Shipment capabilities cannot: the key space is the product of country, weight, package type, delivery type, direction and options, so there is no finite set to fetch in advance.

## Specifications

### Retrieval client

The SDK's `Services\Capabilities\CapabilitiesService` **cannot be used as shipped**. Three defects, reported upstream in Phase 4 and worked around here regardless:

| # | Defect | Effect |
|---|---|---|
| 1 | `Sdk\Services\Capabilities\HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | The user-agent string is JSON-encoded into the request body and the request model is passed as the user agent. Valid JSON of the wrong shape, so a confusing API-side 4xx. **Affects every SDK consumer on beta.25–31.** |
| 2 | The client hardcodes `ShipmentApiFactory::make(null, …)`, and the factory silently resolves a missing key from the environment | Two faults in one. The capabilities client cannot be given a key at all, so it is unusable from this module. Underneath it, **three** factories — `ShipmentApiFactory`, `WebhookApiFactory`, `IamApiFactory` — treat an explicitly empty key as "no key" and substitute `getenv('API_KEY')`, then `API_KEY_NL` / `API_KEY_BE`. See below. |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` keeps only `array_keys($res->getOptions())` | Every per-option value is discarded, including insurance bounds. Unrecoverable downstream. |

**Defect 2 in detail: the safest input produces the most dangerous behaviour.** `resolveApiKey()` is identical in all three factories:

```php
if (null !== $apiKey && '' !== $apiKey) {
    return $apiKey;
}
$unifiedKey = getenv('API_KEY');            // then API_KEY_NL / API_KEY_BE
```

A caller passing `''` — a store with no key configured — does not get an error. It gets whichever account the ambient environment names, and ships a merchant's parcels against it silently. `make()` does guard on an empty resolved key, but only *after* the fallback has failed too, so the guard never fires on the case that matters.

The fix to propose upstream is two lines, distinguishing "not supplied" from "supplied but empty":

```php
if (null !== $apiKey) {
    return $apiKey;      // explicit intent wins, even when empty
}
// environment fallback only when nothing was supplied at all
```

`make('')` then returns `''` and the existing `InvalidArgumentException` fires; `make(null)` and `make('real-key')` are unchanged, so the SDK's own tests and CLI keep working. The same change is needed in all three factories. A cleaner variant — moving the fallback into an explicit `makeFromEnvironment()` — is worth offering, but breaks any consumer relying on implicit resolution.

Until it lands, the module's defence is structural rather than defensive: see [TR-000006](TR-000006-per-api-key-export-batching.md)'s client-construction rules, which keep an unvalidated key from ever reaching a factory.

Two of the three defects independently rule the SDK's service out. Defect 2 means the client cannot be given an API key, and the module needs one per account. Defect 3 means implementing `CapabilitiesClientInterface` does not help either: that interface must return the SDK's `final CapabilitiesResponse`, which has no insurance field, so the values are already gone. Fixing one without the other changes nothing. The module therefore calls the endpoint directly:

| Aspect | Requirement |
|---|---|
| Request construction | SDK `Model\Capabilities\CapabilitiesRequest` + `CapabilitiesMapper::mapToCoreApi()`. Do **not** hand-roll this — see TR-000005 for why. |
| Request granularity | **`packageType` is singular, and the response answers the shape it was asked about.** A request without one returns a superset grouped by carrier: one result covering several package types, carrying the union of their options. Enumerating questions (which carriers, which package types) use the broad call; anything that varies per package type — options, insurance, the collo maximum — must set `packageType`. Reading the broad answer as a matrix over-reports, which is the defect DR-18 records. |
| Client | ~~The per-key client from TR-000006.~~ **Amended.** `ShipmentApi` is not used at all: `Configuration` supplies the host and the `Bearer base64(key)` auth format, `ObjectSerializer::sanitizeForSerialization()` supplies the body, and the module sends the request with its own Guzzle client. `ShipmentApiFactory` is therefore never reached from this layer, so [TR-000006](TR-000006-per-api-key-export-batching.md)'s empty-key hazard is structurally absent rather than guarded, and its "exactly one `make()` call site" assertion still holds. |
| Call | ~~`postCapabilities($request, $userAgent)` — request first.~~ **Amended: not called.** The argument order is the defect, and it differs between the pinned SDK and the target — `($user_agent, $request)` at beta.15, `($request, $user_agent = null)` at beta.31. Not calling the method is what makes this layer version-independent. |
| Response | ~~Read `RefCapabilitiesResponseCapabilityV2` directly.~~ **Amended: read the decoded body.** A generated response model is an allow-list — `ObjectSerializer::deserialize()` iterates the model's own declared properties and there is no `additionalProperties` catch-all, so any key that SDK release does not declare is dropped silently. That is what FR-000010 forbids, and it holds at beta.31 too. It also loses the insurance bounds outright at beta.15, where the flat `min`/`max`/`default` are not declared. Module-owned value objects read the body instead. `mapFromCoreApi()` and `CapabilitiesResponse` remain excluded for the original reason. |
| `Accept` header | `application/json;charset=utf-8;version=2`. ~~Via Guzzle middleware.~~ **Amended: set directly on the request.** Middleware was never reachable: `ShipmentApiFactory::make()` builds its own Guzzle client and `ShipmentApi::$client` is `protected`. Without the header the response may arrive in the V1 shape. Documented nowhere in the SDK; observed in `myparcelnl/pdk`. |
| Options logging | `mapOptions()` silently skips an option with no matching setter. Log when an option we passed does not survive mapping. Two exist today: `fresh_food` and `frozen` appear in a response but have no setter on `CapabilitiesOptionsV2`, so they are read-only. |
| Retirement | The workaround carries a `@todo` referencing the three issues. When they land, reassess whether this layer can shrink to a thin wrapper. |

Contract definitions use `postCapabilitiesContractDefinitions()` through the same client.

**Why the SDK still builds the request but not the response.** The split is not squeamishness about
generated code. On the way out, strictness is what we want: `sanitizeForSerialization()` throws on a
value the API would reject, which is the write-strictly half of the rule below, and
`CapabilitiesMapper` carries domain knowledge we would otherwise rediscover (DR-3). On the way in,
that same strictness is a filter, and a filter on capability data is the exact mechanism FR-000010
exists to prevent.

### Storage

| Data | Varies by | Strategy | Invalidated by |
|---|---|---|---|
| Contract definitions | account | Fetched at account refresh and persisted per API key, alongside the existing account settings row | The *Import MyParcel Backoffice settings* action; API key change |
| Shipment capabilities | account × country × weight × package type × delivery type × direction × options | Cache-aside, keyed on a hash of the request | `bin/magento cache:clean`; API key change; settings import |

Shipment capabilities use a **dedicated Magento cache type**, declared in `etc/cache.xml` with a `TagScope` type class, so `cache:clean myparcelnl_capabilities` and the admin cache page both flush it by its own tag alone. They must **not** go in configuration storage: high-cardinality derived data does not belong in `core_config_data`, where every write invalidates the config cache and appears in config dumps, and there is no admin-triggered moment to hang a refresh off.

**A new cache type is disabled until `env.php` says otherwise, and `setup:upgrade` does not add
one** — verified against 2.4.6, not assumed. `App\Cache\State::isEnabled()` reads `cache_types`
from `app/etc/env.php` and treats an absent key as `false`, so on an upgraded install the type
exists, appears in `cache:status`, and caches nothing. Every checkout would then make an uncached
call, which is the regression this document exists to prevent. The module therefore enables it once
from its upgrade path, and **only when the key is absent** — a type an admin has switched off stays
off, because that switch is what it is for. A write that fails, as on a read-only `app/etc`, logs
the `cache:enable` command rather than failing the upgrade.

### Cache key derivation

| Criterion | Requirement |
|---|---|
| Components | One hash over the API key and the full serialized request payload together. Two separate hashes were specified first; nothing invalidates by account alone, so the second bought nothing but a longer id. |
| API key handling | **Hashed, never plaintext**, in the cache id, the tag, and any log line |
| Shared helper | `MyParcelNL\Magento\Service\Hash\Fingerprint::of()` — the **same** helper as the account settings config path. A second implementation drifting produces a silent cache miss on every request rather than a visible error |
| Completeness | Every field that can change the answer must be in the key. A missing field yields a wrong cached answer, which is worse than no cache |

> Hash helper: `MyParcelNL\Magento\Service\Hash\Fingerprint` (`src/Service/Hash/Fingerprint.php`), landed in #967. `of()` is sha256 as 64 lowercase hex; `LABEL_LENGTH` (12) is the prefix to log instead of a full digest. Also recorded in [TR-000005](TR-000005-sdk-v11-api-mapping.md).

### Defensive consumption

Implementing [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md):

- **Fail open.** On error, timeout or unparseable response: log and continue with permissive defaults. Never block label creation.
- **Serve stale.** Prefer previously cached data over falling through to defaults.
- **Never gate outbound.** Options from stored checkout data, bulk parameters or REST are sent regardless of what capability data lists. No allow-list filter — deliberately unlike `myparcelnl/pdk`.
- **Null-safe reads.** Iterate what the response contains; assume no key exists.
- **Log unknown values.** Every v2 carrier, package type, delivery type and option key the module cannot translate is kept and logged at notice, once per fetch. ~~Register a listener on `Support\EnumFallback`.~~ **Moved to the phases that deserialize SDK models.** `EnumFallback` fires inside `ObjectSerializer::deserialize()`, which the capabilities path no longer calls, and it does not exist at beta.15 — so there is nothing for it to observe here and no way to register it on the pinned SDK. Read leniently; note that request serialization stays strict, so module-constructed enums are validated before sending.

### Insurance bounds

Read the **flat** `min` / `max` / `default` properties on the insurance option — confirmed populated by the API. Not the nested `insured_amount` wrapper, which the spec marks deprecated and which `myparcelnl/pdk` still reads.

| Context | Source |
|---|---|
| A concrete shipment | Shipment capabilities → `options.insurance` |
| Admin configuration (no shipment) | Contract definitions → insurance option, which additionally carries `is_required` and `is_selected_by_default` |

### Performance criteria

| Metric | Requirement | Measurement |
|---|---|---|
| Uncached capability calls per cold checkout | ≤ 1 per distinct account and request shape. Checkout resolves one package type before it asks, so that is one call | Request log during a checkout |
| Uncached capability calls per warm checkout | 0 | Request log |
| Uncached calls per admin *New Shipment* render (warm) | 0 | Request log |
| Uncached calls per admin *New Shipment* render (cold) | One broad call plus one per distinct package type offered, so ≤ 8. Per package type, never per carrier — FR-000008's criterion is about carriers | Request log |
| Checkout delivery-options latency vs beta.15 | No measurable regression once warm | Before/after timing |
| Label creation with capabilities unavailable | Succeeds | Fault injection |

## Verification Method

Unit tests with a stubbed client for behaviour, fault injection for degradation, and request-log inspection for the caching criteria.

### Test Scenarios

1. **Correct request shape.** The outbound request carries V2 wire keys, the `version=2` `Accept` header, and the store's own API key — asserted against a mocked `ShipmentApi`.
2. **Option values survive.** A stubbed response containing `insurance` with `min`, `max` and `default` yields those values to the caller, proving the `mapFromCoreApi()` path is not in use.
3. **Per-account isolation.** Two keys with different stubbed responses produce different option sets for the same shipment input, and neither is served from the other's cache entry.
4. **Cache key completeness.** Changing any request field (country, weight, package type, delivery type, an option) produces a cache miss; repeating an identical request produces a hit.
4b. **Granularity.** A broad response whose single result lists several package types must not answer a per-package-type question. Assert with a broad superset and a narrowed response that disagree — a mailbox that the superset says may be oversized and insured, and a narrowed answer that says neither.
5. **No plaintext key.** No cache id, tag or log line contains the API key. Assert against a recognisable test key value.
6. **Degradation.** Four injected faults — HTTP 500, timeout, a response with an extra unknown option, a response missing an expected key — each leave the form rendering and label creation working.
7. **Stale preference.** With data cached and a refresh failing, the cached value is used rather than defaults.
8. **Unknown value logging.** A response containing an unknown carrier, package type, delivery type or option key is logged and does not throw, and the recognised half of the same response still answers.
9. **Invalidation.** Changing the API key, and running the settings import, both invalidate the affected entries. `cache:clean` flushes the cache type.
10. **Offline upgrade.** `setup:upgrade` on a pre-migration database succeeds with no network access.

### Monitoring

- Log capability call failures at warning level with the account's hashed key and the request shape — never the key itself.
- Log unknown enum values and unmapped options at notice level. These are the early-warning signal that the module needs updating for an upstream change.

## Assumptions

- The capabilities endpoint's V2 response remains the shape observed at beta.31. Where the OpenAPI spec and an observed acceptance response disagree, the observed response wins. **Confirmed as the right posture** — the shape is stable in practice but outside this module's control, which is exactly why the defensive-consumption rules above are mandatory rather than cautious.
- Contract definitions change rarely enough that account-refresh cadence is sufficient freshness. **Confirmed** — fetching at account refresh is the intended strategy; no shorter TTL is needed.
- The prerequisite PR's hash helper is available and stable. **Confirmed** — #967 merged, and `Service\Hash\Fingerprint` is the helper. It is deliberately ignorant of what it hashes, so the cache id needs no change to it. One consequence to respect: its output is the lookup key for rows already stored, so a change to `of()` invalidates the cache *and* orphans the account settings rows.

## Constraints

- The SDK's own capabilities service cannot be used until the three defects land; we must not patch `vendor/**`.
- The SDK knows one host, `https://api.myparcel.nl`, and no acceptance counterpart. The client takes an optional host override, which is the only seam for verifying against acceptance.
- `CapabilitiesResponse` is `final` with a 6-argument positional constructor, so it cannot be extended to carry insurance.
- `MultiColloShipmentService` takes no API key. Do not build it per key. (`CapabilitiesService` also takes none, which is defect 2 — the module does not use it at all.)
- Capability lookups must never be made from `src/Setup/UpgradeData.php`; upgrades must work offline.
