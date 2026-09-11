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

Capability data is retrieved per MyParcel account, cached so that no user-facing request makes an uncached call it could avoid, and consumed defensively so that a change in the response cannot break the module.

Two classes of data are stored differently: **contract definitions** (account-level, no shipment context) are fetched and persisted at account refresh; **shipment capabilities** (dependent on the shipment) are cached lazily on a hash of the request.

## Rationale

These questions used to be answered in-process with zero I/O. Moving them to an HTTP endpoint puts a network call on two hot paths, checkout and the admin *New Shipment* form. Uncached, that is a latency regression for every merchant and a new dependency for checkout.

The storage split follows the data. Contract definitions are bounded and enumerable, so they can be fetched at a known moment and stored like other account data. Shipment capabilities cannot: the key space is the product of country, weight, package type, delivery type, direction and options.

## Specifications

### Retrieval client

The SDK's `Services\Capabilities\CapabilitiesService` cannot be used as shipped. Three defects, reported upstream and worked around here:

| # | Defect | Effect |
|---|---|---|
| 1 | `Sdk\Services\Capabilities\HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | The user-agent string is JSON-encoded into the body and the request model is passed as the user agent: a confusing API-side 4xx. Affects every SDK consumer on beta.25 and later |
| 2 | The client hardcodes `ShipmentApiFactory::make(null, …)`, and `ShipmentApiFactory`, `WebhookApiFactory` and `IamApiFactory` treat an explicitly empty key as "no key" and substitute `getenv('API_KEY')`, then `API_KEY_NL` / `API_KEY_BE` | The capabilities client cannot be given a key at all, and a store with no key would ship against whatever account the environment names. The upstream fix is to return an explicitly supplied key even when empty and fall back to the environment only when nothing was supplied |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` keeps only `array_keys($res->getOptions())` | Every per-option value is discarded, including insurance bounds |

Defects 2 and 3 independently rule the SDK's service out, and `CapabilitiesClientInterface` does not help: it must return the SDK's `final CapabilitiesResponse`, which has no insurance field. The module therefore calls the endpoint itself:

| Aspect | Rule |
|---|---|
| Request construction | SDK `Model\Capabilities\CapabilitiesRequest` + `CapabilitiesMapper::mapToCoreApi()`. Never hand-rolled; see TR-000005 |
| Request granularity | **`packageType` is singular, and the response answers the shape it was asked about.** A request without one returns a superset grouped by carrier: one result covering several package types with the union of their options. Enumerating questions (which carriers, which package types) use the broad call; anything that varies per package type (options, insurance, the collo maximum) sets `packageType`. Reading the broad answer as a matrix over-reports |
| Client | `ShipmentApi` is not used. `Configuration` supplies the host and the `Bearer base64(key)` auth format, `ObjectSerializer::sanitizeForSerialization()` the body, and the module sends the request with its own Guzzle client. `ShipmentApiFactory` is never reached from this layer |
| Response | The decoded body is read into module-owned value objects under `src/Model/Shipment/Capabilities/`. A generated response model is an allow-list: `ObjectSerializer::deserialize()` reads only the model's declared properties and there is no `additionalProperties` catch-all, so any key that SDK release does not declare is dropped silently, which FR-000010 forbids |
| `Accept` header | `application/json;charset=utf-8;version=2`, set directly on the request. Without it the response may arrive in the V1 shape. Documented nowhere in the SDK; observed in `myparcelnl/pdk` |
| Throttling | A 429, and defensively 503 and 529, is retried **once**, honouring `Retry-After` in seconds or HTTP-date form. A `Retry-After` longer than 2s is not waited out; the negative cache entry below is a better answer than a hanging page. A timeout is never retried |
| Unknown options | Every option key the module cannot translate is logged, once per fetch |
| Retirement | The workaround carries a `@todo` referencing the three issues. When they land, reassess whether this layer can shrink to a thin wrapper |

**Contract definitions** go through the same client: `postCapabilitiesContractDefinitions()` carries the same reversed-argument defect, so the module posts to `/shipments/capabilities/contract-definitions` itself, reusing the auth, `Accept` header, retry ladder and host override.

| | Contract definitions | Shipment capabilities |
|---|---|---|
| Request body | `{carrier}`, one string, so **one call per carrier** and no country | The mapped `CapabilitiesRequest`, country included |
| Response envelope | `items` | `results` |
| Entry keys | `carrier`, `packageTypes`, `options`, `deliveryTypes`, `transactionTypes`, `collo` | the same, plus `contract` and `physicalProperties` |

An entry is a strict subset of a capabilities result, so one parser reads both: `CapabilitySet::fromContractDefinitionItems()` is a named constructor over the same code. **There is no country or zone anywhere in a contract-definitions request or response**, so a per-destination answer can only come from shipment capabilities.

**Why the SDK builds the request but not the response.** On the way out, strictness is what we want: `sanitizeForSerialization()` throws on a value the API would reject, and `CapabilitiesMapper` carries domain knowledge we would otherwise rediscover. On the way in, the same strictness is a filter, and a filter on capability data is the mechanism FR-000010 exists to prevent.

### Storage

| Data | Varies by | Strategy | Invalidated by |
|---|---|---|---|
| Contract definitions | account | Fetched at account refresh and persisted per API key, under `contract_definitions` in the account settings row, verbatim | The *Import MyParcel Backoffice settings* action; API key change |
| Shipment capabilities | account × country × weight × package type × delivery type × direction × options | Cache-aside, keyed on a hash of the request | `bin/magento cache:clean`; API key change; settings import |
| A shape that failed | the same | A marker entry under its own id prefix, **60s lifetime** | Expiry, plus everything above |

Shipment capabilities use a **dedicated Magento cache type**, declared in `etc/cache.xml` with a `TagScope` type class, so `cache:clean myparcelnl_capabilities` and the admin cache page flush it alone. They do **not** go in configuration storage: high-cardinality derived data does not belong in `core_config_data`, where every write invalidates the config cache and appears in config dumps.

**A failure is cached too, briefly.** Successful entries never expire; a failure expires after 60 seconds. Without that, an admin form fanning out over several package types repeats the whole burst on every reload, which is the load a 429 asks us to stop applying. The marker is checked *after* the success entry, so a previous good answer always beats a recent failure.

**A new cache type is disabled until `env.php` says otherwise, and `setup:upgrade` does not add one.** `App\Cache\State::isEnabled()` reads `cache_types` from `app/etc/env.php` and treats an absent key as `false`, so on an upgraded install the type appears in `cache:status` and caches nothing. `Setup\Migrations\EnableCapabilitiesCache` enables it once from the upgrade path, **only when the key is absent**: a type an admin switched off stays off. A write that fails, as on a read-only `app/etc`, logs the `cache:enable` command rather than failing the upgrade.

### Cache key derivation

| Criterion | Requirement |
|---|---|
| Components | One hash over the API key and the full serialized request payload together |
| API key handling | **Hashed, never plaintext**, in the cache id, the tag and any log line |
| Shared helper | `MyParcelNL\Magento\Service\Hash\Fingerprint::of()`, the **same** helper as the account settings config path. A second implementation drifting produces a silent cache miss on every request |
| Completeness | Every field that can change the answer is in the key. A missing field yields a wrong cached answer, worse than no cache |

### Defensive consumption

Implementing [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md):

- **Fail open.** On error, timeout or unparseable response: log and continue with permissive defaults. Never block label creation.
- **Serve stale.** Prefer cached data over defaults.
- **Never gate outbound.** Options from stored checkout data, bulk parameters or REST are sent regardless of what capability data lists.
- **Null-safe reads.** Iterate what the response contains; assume no key exists.
- **Log unknown values.** Every v2 carrier, package type, delivery type and option key the module cannot translate is kept and logged at notice, once per fetch. Request serialization stays strict, so module-constructed enums are validated before sending.

### Insurance bounds

Read the **flat** `min` / `max` / `default` properties on the insurance option, confirmed populated by the API. Not the nested `insured_amount` wrapper, which the spec marks deprecated and which `myparcelnl/pdk` still reads. `Model\Shipment\Capabilities\InsuranceRange` reads the flat properties and nothing else; `Tests/Unit/Model/Shipment/Capabilities/InsuranceShapeConformanceTest.php` holds a captured acceptance response to that shape. Acceptance answers with both shapes carrying identical values, which is why the fixture contains `insuredAmount`: it records the wire. A body carrying only the wrapper yields no range, and the caller falls open to an unbounded field rather than losing insurance.

**Cents in, euros out, in one place.** Each bound is a `Money` object whose `amount` is in **cents**, while every stored setting and module value object is in whole euros. The conversion lives in `InsuranceRange` and rounds inwards, a minimum up and a maximum down, so a fractional bound never widens the range. A maximum that rounds below one euro yields no range: `[0,0]` would make `clamp()` answer zero for every amount, an uninsured parcel. The other two money scales are in TR-000005.

| Context | Source | Authority |
|---|---|---|
| A concrete shipment | Shipment capabilities → `options.insurance`, asked with the package type set | **Authoritative.** `ShipmentOptionsResolver` clamps here, and it is the only clamp |
| Admin configuration, no shipment | Contract definitions → insurance option, which also carries `is_required` and `is_selected_by_default` | **Advisory.** Contract definitions carry no country, so this bounds the carrier, not the destination |

The settings screen states its range and refuses an out-of-range entry on save; export clamps and logs. Screen and save both read the bound through `Service\AccountSettings\ContractDefinitions::insuranceRangeFor()`, so what a field promises and what a save enforces cannot drift.

**Zero is not part of the range, and `is_required` governs it.** A contract minimum bounds what an *insured* parcel may be insured for; it does not make insurance compulsory. The permitted set is `[min, max]` when the option is required, and `[min, max]` plus zero when it is not, confirmed with the MyParcel team. An amount strictly between zero and the minimum is refused either way. An option that does not state `is_required` counts as optional.

Enforcement on save is a `Model\Settings\Validator\SettingValidatorInterface`, wired into `Observer\ConfigChange` through `etc/di.xml` and asked `handles($path)` before it judges anything. A rejection costs that one field, since the form posts every field on every submit. `Model\Settings\InsuranceAmountSetting::carrierFor()` is the one place that recognises an insurance amount by its config path. A value that is not a whole number of euros is refused rather than saved: every reader coerces a non-numeric amount to `0`, which would switch insurance off silently.

On the export path zero means the insurance option is **left out of the request**. An order below the configured `insurance_from_price` therefore ships uninsured whatever the contract says, and if the contract required insurance the API refuses the shipment: the visible failure FR-000010 prefers to a silent substitution.

### Performance criteria

| Metric | Requirement | Measurement |
|---|---|---|
| Uncached capability calls per cold checkout | ≤ 1 per distinct account and request shape; checkout resolves one package type before it asks | Request log |
| Uncached capability calls per warm checkout | 0 | Request log |
| Uncached calls per admin *New Shipment* render, warm | 0 | Request log |
| Uncached calls per admin *New Shipment* render, cold | One broad call plus one per distinct package type offered, so ≤ 8. Per package type, never per carrier | Request log |
| Checkout delivery-options latency vs beta.15 | No measurable regression once warm | Before/after timing |
| Label creation with capabilities unavailable | Succeeds | Fault injection |

## Verification Method

Unit tests with a stubbed client for behaviour, fault injection for degradation, and request-log inspection for the caching criteria.

### Test Scenarios

1. **Correct request shape.** The outbound request carries V2 wire keys, the `version=2` `Accept` header and the store's own API key.
2. **Option values survive.** A stubbed response with `insurance` `min`, `max` and `default` yields those values to the caller.
3. **Per-account isolation.** Two keys with different stubbed responses produce different option sets for the same input, and neither is served from the other's cache entry.
4. **Cache key completeness.** Changing any request field produces a miss; repeating an identical request produces a hit.
5. **Granularity.** A broad response listing several package types in one result must not answer a per-package-type question. Assert with a broad superset and a narrowed response that disagree.
6. **No plaintext key.** No cache id, tag or log line contains the API key.
7. **Degradation.** HTTP 500, timeout, an extra unknown option, a missing expected key: each leaves the form rendering and label creation working.
8. **Throttling.** A 429 then a 200 yields the 200 in exactly two requests. Two 429s stop at two. A `Retry-After` of 300s makes one request and no wait. A timeout is not retried. A failed shape writes a 60s marker, and a second lookup inside that window makes no request.
9. **Partial failure is visible.** With some shapes answered and others failed, the admin form announces the fallback on the page, asserted through the block's own flag.
10. **Stale preference.** With data cached and a refresh failing, the cached value is used.
11. **Unknown value logging.** A response with an unknown carrier, package type, delivery type or option key is logged and does not throw, and the recognised half still answers.
12. **Invalidation.** Changing the API key and running the settings import both invalidate the affected entries. `cache:clean` flushes the cache type.
13. **Offline upgrade.** `setup:upgrade` on a pre-migration database succeeds with no network access.

### Monitoring

- Log capability call failures at warning level with the account's hashed key and the request shape, never the key itself.
- Log unknown enum values and unmapped options at notice level. These are the early-warning signal that the module needs updating.

## Assumptions

- The capabilities endpoint's V2 response keeps the observed shape. Where the OpenAPI spec and an observed acceptance response disagree, the observed response wins, which is why the defensive-consumption rules are mandatory rather than cautious.
- Contract definitions change rarely enough that account-refresh cadence is sufficient freshness.
- `Service\Hash\Fingerprint` is stable. Its output is the lookup key for rows already stored, so a change to `of()` invalidates the cache **and** orphans the account settings rows.

## Constraints

- The SDK's own capabilities service cannot be used until the three defects land; `vendor/**` is not patched.
- The SDK knows one host, `https://api.myparcel.nl`. The client takes an optional host override, the only seam for verifying against acceptance.
- `CapabilitiesResponse` is `final` with a positional constructor and cannot be extended to carry insurance.
- `MultiColloShipmentService` takes no API key; it is not built per key.
- Capability lookups are never made from `src/Setup/UpgradeData.php`; upgrades work offline.
