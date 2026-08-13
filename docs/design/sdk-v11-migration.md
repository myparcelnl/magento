# SDK v11 Migration Plan

**Status:** Phase 0 in progress
**Started:** 2026-08-11
**Branch:** `feat/use-sdk-v11-shipments`
**Business Requirement:** [BR-000003 — MyParcel SDK v11 compatibility](../business-requirements/BR-000003-sdk-v11-compatibility.md)

This is the working plan for migrating the module from `myparcelnl/sdk` beta.15 to beta.31. It is a **living document**: it is updated in the same commit as the phase it describes, and when reality diverges from it, the plan changes and says why. The decision records in it are the most valuable part — each one is an assumption that looked right and was not.

---

## Why this migration is happening

The module pins `myparcelnl/sdk: 11.0.0-beta.15@beta`. SDK **beta.22** deleted the entire legacy consignment stack, so the module cannot run on beta.23 or later. The goal is compatibility with **beta.31**, plus re-implementing the multi-API-key ("multi MyParcel store") batch export that the SDK dropped along with `MyParcelCollection`.

### What the investigation established

| Fact | Consequence |
|---|---|
| All breaking changes landed in **beta.22** (`ac6103c`, PR #615). Only beta.29 (enum validation loosening) also flags BREAKING. | One cliff, not a drift. |
| The v11 stack (`Model\Shipment\Shipment`, `Collection\ShipmentCollection`, `Services\Shipment\*`) **already exists at beta.15**, byte-identical to beta.31 for the model and collection. | **The whole module can be migrated against the currently-installed SDK, bumping the pin last.** Every intermediate commit stays runnable. |
| `vendor/myparcelnl/sdk` is a **symlink** to `app/code/MyParcelNL/Sdk`, a git checkout currently at **beta.15**, matching the pin exactly. | Local verification is a `git checkout` in that repo — switch versions there to check behaviour at a different beta. |
| The SDK's `UPGRADE.md` at beta.31 is an authoritative before/after migration guide. | Primary reference — read it, do not re-derive it. |
| **54 module files** touch the SDK; **34** touch classes beta.31 removed. | Broad but mostly shallow. |
| `AbstractConsignment` usage is **~41 constants / 120+ usages** and only ~7 real type usages. | The largest slice is mechanical constant replacement. |
| `Shipment` has **no `setApiKey()`**; every v11 service takes the key as its **first constructor argument** and is `final` and immutable. | Grouping by API key is now entirely the consumer's job. |
| beta.31 has **no cross-key label PDF merge** (beta.15's `setPdfOfLabels()` merged with FPDI). | Merging is re-implemented in Magento. |
| `ShipmentCreateService::create()` rejects **>100 shipments** per call — but large batches time out well below that. | Chunk at a **configurable size, default 20**. The SDK limit is the ceiling, not the target. |
| PHP floor unchanged (`^7.4 \|\| ^8.0`); guzzle `^7.10` already installed (7.10.0); `setasign/fpdi` v2.6.6 present. | No platform risk. |
| Fulfilment/PPS (`OrderCollection::save()`) still groups by per-order API key, **unchanged**. | PPS multi-key keeps working for free. |
| The shipment/label flow has **zero automated test coverage** today. | Pin our own rules with hand-written behaviour tests before refactoring (Phase 1). No snapshots — see Phase 1. |

### The sleeper: capability probes

Beyond shipment creation, the module builds throwaway consignments purely to *ask questions*: `canHaveShipmentOption()`, `canHaveDeliveryType()`, `canHavePackageType()`, `canHaveExtraOption()`, `getAllowedPackageTypes()`, `getAllowedShipmentOptions()`, `getInsurancePossibilities()`, `getLocalCountryCode()`, `isToRowCountry()`. All are gone at beta.31.

This drives the admin *New Shipment* form (`view/adminhtml/templates/new_shipment.phtml`), checkout (`src/Model/Quote/Checkout.php`), 16 insurance virtual types in `etc/di.xml`, and `src/Setup/UpgradeData.php`. beta.31's answers are the **capabilities** and **contract-definitions** endpoints. This is the largest slice of the work, and the one place where behaviour stops being hardcoded and starts being account-specific.

Two `BaseConsignment` usages are **constructor-injected via Magento DI** (`src/Block/Sales/OrderAction.php:41`, `src/Block/Sales/ShipmentAction.php:48`) with no `di.xml` entry — invisible to an XML grep, and they break `setup:di:compile`.

### The PDK is the reference implementation

`myparcelnl/pdk` has already done this migration: v4.7.1 requires `myparcelnl/sdk: ^11.0.0-beta.30@beta`. Read it before designing anything in Phases 4 or 5. It settles the client shape and the caching split, and it carries at least one requirement documented nowhere else (the V2 `Accept` header).

We deviate from it deliberately in three places, all recorded below.

---

## Decision records

Corrections made while planning. Each was wrong in a way that is easy to repeat.

### DR-1: Capabilities are per-account, not store-agnostic

**Initially assumed:** Phase 4 needs no API key, because `CapabilitiesService::__construct` takes none.

**Wrong because:** capabilities differ per MyParcel account — different carrier contracts give different options. The layer must be API-key scoped. The key is not absent from the request, merely unreachable: `HttpCapabilitiesClient` hardcodes `ShipmentApiFactory::make(null, …)`, so it resolves only from `getenv('API_KEY')` / `API_KEY_NL` / `API_KEY_BE` and otherwise throws.

### DR-2: `CapabilitiesResponse` cannot carry what we need

**Initially assumed:** implement `CapabilitiesClientInterface` and plug into `CapabilitiesService`.

**Wrong because:** that interface must return the SDK's `final CapabilitiesResponse`, which has no insurance field. `CapabilitiesMapper::mapFromCoreApi()` reduces the options map to `array_keys(...)`, discarding every option value including insurance bounds. No amount of correct wiring on our side recovers a value already thrown away, so we read `RefCapabilitiesResponseCapabilityV2` directly.

### DR-3: `mapToCoreApi()` is fine; do not port PDK's `hydrateModel()`

**Initially assumed:** port PDK's private `hydrateModel()`, because `mapToCoreApi()` was unverified.

**Wrong because:** verified, and it is correct — structurally, since it never constructs wire keys. It builds typed `CapabilitiesRecipientV2` / `SenderV2` / `OptionsV2` / `PhysicalPropertiesV2` / `Pickup` models through typed setters, so keys come from each generated model's own `attributeMap` at serialization time. It also carries domain knowledge we would otherwise rediscover, and handles a quirk we would likely get wrong. See TR-000007.

### DR-4: Insurance tiers are dropped entirely

**Initially assumed:** first that tiers had no successor and should go; then, after finding PDK's `InsuranceTierMath`, that a ladder should be derived from the range.

**Settled:** the API accepts **any** value between `min` and `max` (verified directly against the API). Tiers are a pure client-side construct with no API meaning. PDK synthesises a ladder anyway; we do not port it. Free numeric input is more useful to the merchant and one less piece of logic to keep in sync. Migration is also easier this way: every currently-saved tier value is inside the range, so the new domain is a superset of the old.

### DR-5: Test our rules, not carrier facts

**Initially assumed:** Phase 1 should pin behaviours like "age check forces package type", "multicollo = PostNL + NL/BE + package", "small package outside NL has no delivery date".

**Wrong because:** those are not our rules. They are today's PostNL truth, hardcoded because the old SDK could not be asked. After this migration they follow from a capabilities response and may differ per account. Pinning them would lock in exactly what we are making dynamic. They moved to Phase 4, tested against a stubbed capabilities response.

### DR-6: `account_settings_{apiKey}` scoping is not a bug

**Initially reported as** a read/write scope mismatch.

**Wrong because:** the write (`CarrierConfigurationImport.php:70`) and the read (`AccountSettings.php:34`) are *both* default-scope. The API key in the path is the discriminator and it works as designed. Only the API key *lookup* is scope-aware, which is correct. Two narrower real items replaced it: `PackageRepository.php:134` reads the key without an explicit store id, and `AccountSettings.php:13,15` import classes absent from beta.15 and beta.31.

---

## Standing decisions

- **Git:** one PR on `feat/use-sdk-v11-shipments`, **one commit per phase**. The composer bump is the last code commit.
- **Multi-account label PDF:** merge into a single PDF in Magento via `setasign/fpdi`, preserving today's UX. Add fpdi as an explicit module dependency — beta.31 keeps it in `require` but no longer uses it.
- **Carrier options:** migrate to contract definitions now, not deferred. The SDK warns this is not a 1:1 replacement for the `CarrierOptions` model; consumers read the generated contract-definition models directly.
- **Latent multi-store bugs in scope:** cron PPS status polling (`src/Cron/UpdateStatus.php:126` polls one key only) and return labels using the wrong key.
- **We do not touch the SDK.** No PR, no `vendor/` patch (per `CLAUDE.md`). Three defects are raised as issues; the module works around them.

---

## Prerequisite: separate PR, lands before this one

Built on the fly, with **no requirement documents** — small, self-contained, rationale lives in its PR description.

**Naming note:** the path is `myparcelnl_magento_general/account_settings_{apiKey}`, not `account_data_`. Only two call sites exist — `src/Model/Settings/AccountSettings.php:35` (read) and `Controller/Adminhtml/Settings/CarrierConfigurationImport.php:71` (write) — so the change is contained, but a cleanup routine must match the real prefix or it will run clean and delete nothing, which looks like success.

**1. Hash the API key in the config path.** The plaintext key is currently part of a `core_config_data` path, which leaks it through `bin/magento config:show`, `config:dump`, support exports, and anyone with config-table read access. Use **one shared hash helper** for both the config suffix and the Phase 4 cache id — two implementations drifting means a silent cache miss on every request rather than a visible error. Migration is lossless and needs no re-import: the existing suffix *is* the plaintext key, so a data patch can read `account_settings_<plain>`, write `account_settings_<hash>`, and delete the original. Keep it idempotent; a row already hashed must be left alone.

**2. Clean up orphaned rows.** Collect the hashes of every API key currently configured **across all scopes**, enumerate `account_settings_*`, delete those not in the set. Two hazards: a key configured only at store-view or website scope must not look orphaned (`Service\Settings::hasOwnValue()` and `hasRowAtScope()` already have the partition semantics — getting this wrong deletes live config on exactly the multi-store installs this project targets); and trigger on explicit events only — after a settings import, on API key add/change/remove — not on arbitrary config writes, since a partly-saved form can transiently look like a removal. Log every deletion. Consider report-only first.

**Two facts must survive that PR**, because this plan depends on them: the **name and location of the shared hash helper** (recorded in TR-000007) and the **final config path shape** (recorded in TR-000005).

**Why first:** Phase 5 extends this storage to hold contract definitions. Doing that on the plaintext scheme means writing rows we would immediately migrate, and spreading the plaintext key to a second path.

---

## Strategy

Build a **module-owned shipment domain layer**, then swap the SDK underneath it. Both SDK stacks coexist up to beta.21, so Phases 1–8 are developed and verified against the installed SDK with the old stack present as a live reference; Phase 9 flips the pin.

New module code lands under:

- `src/Model/Shipment/` — `PackageType`, `DeliveryType`, `ShipmentOption`, `CountryCode` (constant facades); `Capabilities`; `ShipmentBuilder`
- `src/Adapter/DeliveryOptions/` — module-owned `DeliveryOptions`, `ShipmentOptions`, `PickupLocation` value objects and a factory
- `src/Service/Export/` — `ShipmentExportService` (per-key batching), `LabelPdfMerger`
- `src/Service/TrackTraceUrl.php`, `src/Service/ValidatePostalCode.php`

Every new class gets a class-level doc block explaining responsibility and invariants (per `CLAUDE.md`).

---

## Phases

### Phase 0 — Commit this plan, then the requirements documents · *In progress*

This document is the first commit of the PR. It is canonical from the moment it lands; the harness plan file is scratch from then on.

Then create, following `docs/templates/` and matching BR-000002's depth: BR-000003, FR-000006 through FR-000010, TR-000005 through TR-000007, and US-000007 through US-000011. See the traceability matrix below.

**Check:** every document's Traceability / Parent Requirement section resolves, and the matrix has no phase without a requirement and no requirement without a phase. Use the `ai-basekit:orchestrator` agent to generate the matrix and run an ecosystem health check rather than eyeballing links.

### Phase 1 — Tests for the rules that are *ours* · *Not started*

**No snapshots** (see DR-5) and **no carrier facts**. This phase covers only rules that stay ours regardless of what the API says: precedence, arithmetic, plumbing, error paths.

| Behaviour | Where it lives today |
|---|---|
| Package type **precedence**: which source wins (explicit option → `deliveryOptions['packageType']` → config default), and the name→id mapping. *Not* which package types are legal. | `TrackTraceHolder::getPackageType()` `:462-479` |
| Weight arithmetic: digital stamp always grams; otherwise Σ(item weight × qty) + empty package weight; unit conversion | `TrackTraceHolder::calculateTotalWeight()` `:308-327`, `src/Service/Weight.php` |
| Age check **precedence**: options → product attribute → carrier config. *Not* what age check then implies. | `TrackTraceHolder::getAgeCheck()` `:396-407` |
| Customs item mapping field-by-field — **and the double-add**: `:347-361` and `:367-382` both loop | `TrackTraceHolder::convertDataForCdCountry()` |
| Carrier override clears an inherited pickup location | `TrackTraceHolder.php:112-116` |
| API key resolved from the order's store; empty key raises `LocalizedException` | `TrackTraceHolder.php:118-123` |
| Street/postcode splitting and validation pass-through | `MagentoOrderCollection.php:425-446` |

- Shared order/track/address builders go in `Tests/Helpers/` — one set, reused by every test.
- `Tests/bootstrap.php:29-46` stubs unresolvable `Magento\*` classes but deliberately **not** `MyParcelNL\Sdk\*`, so these run against the real SDK.
- The customs double-add is a pre-existing bug. Assert the correct behaviour (each item once), let it fail here, and it goes green in Phase 6. Mark it `->todo()` so the suite stays green in between.

**Check:** `vendor/bin/pest` green bar the one documented expected-fail. If a test's expected value depends on *which carrier* or *which country*, it belongs in Phase 4.

### Phase 2 — Module-owned constants and small helpers · *Not started*

- `AbstractConsignment::{CC_*, PACKAGE_TYPE_*, PACKAGE_TYPES_*_MAP, DELIVERY_TYPE_*, SHIPMENT_OPTION_*, EURO_COUNTRIES, …}` → `src/Model/Shipment/{CountryCode,PackageType,DeliveryType,ShipmentOption}`, delegating to SDK `Model\Shipment\{PackageType,Carrier}` and `Mapping\*` where an id↔name mapping is needed.
- `Sdk\Helper\TrackTraceUrl` → `src/Service/TrackTraceUrl` (used at `src/Ui/Component/Listing/Column/TrackAndTrace.php:118` and `src/Block/DataProviders/Email/Shipment/TrackingUrl.php:32,61` — de-duplicate that repeated call).
- `Sdk\Helper\ValidatePostalCode` → `src/Service/ValidatePostalCode`.
- Give `PackageRepository.php:134` an explicit store id.
- Validate our own **outbound** enums here: `EnumFallback` is forgiving on the read path but request serialization stays strict.

`Services\CountryCodes` and `Support\{Str,Collection}` survive in beta.31 — leave them alone.

**Check:** unit tests asserting every new constant equals the beta.15 SDK value it replaces. Grep shows zero remaining `AbstractConsignment::` references.

### Phase 3 — Module-owned delivery options value objects · *Not started*

Replace `Sdk\Adapter\DeliveryOptions\*` and `Factory\DeliveryOptionsAdapterFactory` (13 files) with module-owned immutable value objects and a factory. `src/Adapter/DeliveryOptionsFromOrderAdapter.php` and `ShipmentOptionsFromAdapter.php` currently extend SDK abstracts and write their protected properties directly; that coupling disappears here.

Touches `src/Model/Quote/Checkout.php`, `src/Model/Checkout/ShippingMethods.php`, `src/Service/NeedsQuoteProps.php`, `src/Model/Carrier/Carrier.php`, `src/Block/Sales/View.php`, `src/Model/Rest/{OrderDeliveryOptions.php, Request/OrderDeliveryOptionsV1Request.php, Transformer/{ShipmentOptionsTransformer,PickupLocationTransformer}.php}`, `src/Plugin/Magento/Sales/Api/Data/OrderInformationUpdate.php`, `src/Model/Source/DefaultOptions.php`, `Tests/Helpers/DeliveryOptionsMocks.php`.

**Check:** `Tests/Unit/Model/Rest/OpenApiConformanceTest.php` and the transformer tests stay green — the versioned REST response must be byte-identical.

### Phase 4 — Capabilities layer · *Not started*

`src/Model/Shipment/Capabilities`: module-owned, API-key-scoped, cached. Calls `postCapabilities` directly (DR-1, DR-2). Full specification in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

Touches `view/adminhtml/templates/new_shipment.phtml` (heaviest), `src/Block/Sales/{NewShipment,NewShipmentForm}.php`, `src/Model/Quote/Checkout.php`, `src/Block/Sales/{OrderAction,ShipmentAction}.php` (**drop the `BaseConsignment` DI argument** — this is what breaks `di:compile`), `src/Helper/CustomsDeclarationFromOrder.php`, `src/Model/Sales/MagentoOrderCollection.php:425`.

**SDK issues to raise — manual, this phase.** Three separate issues on `myparcelnl/sdk`:

| # | Issue | Severity | Notes for the write-up |
|---|---|---|---|
| 1 | `HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | **Broken for every consumer on beta.25–31** | The user-agent string passes the null/empty-array guard and is `jsonEncode`d into the body as a bare JSON string, while the request model goes to `ObjectSerializer::toHeaderValue()`. Valid JSON of the wrong shape, so a confusing API-side 4xx rather than a clear local error. Ask for a regression test exercising `HttpCapabilitiesClient` itself — the coverage gap is why it shipped. |
| 2 | `HttpCapabilitiesClient` / `CapabilitiesService` accept no API key | Blocks all library use | Suggest an appended optional constructor arg on both (non-breaking), and argue the `getenv` fallback should not fire for library consumers — an explicitly empty key should fail rather than silently pick up an ambient one. |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` drops all option values | Design question, not a bug | Propose rather than prescribe: `CapabilitiesResponse` is `final` with a 6-arg positional constructor the SDK's own test calls positionally, so adding insurance means a 7th positional arg or exposing the raw models. Note our client is close to the latter — that may speed agreement. |

Reference `UPGRADE.md`'s claim that `CapabilitiesService` is the v11 answer for capabilities, since 1–3 together make it untrue in practice. When they land, delete the workaround behind its `@todo` and reassess whether this layer can shrink.

**Check:** `setup:di:compile` succeeds. The admin New Shipment form renders the same package types, delivery types and shipment options as on beta.15, per carrier (insurance changes shape in Phase 5). Checkout delivery options unchanged. A cold checkout makes at most one capabilities call per (account, request shape); a warm one makes none.

### Phase 5 — Insurance as a range, contract definitions, account settings · *Not started*

Insurance becomes a free amount between `min` and `max` (DR-4). Specification in [FR-000009](../functional-requirements/FR-000009-insurance-as-a-range.md) and [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

- `src/Model/Source/CarrierInsurancePossibilities.php` and the **16 virtual types at `etc/di.xml:74-194`** exist only to populate tier dropdowns, so they go. Convert the 16 `source_model` references in `etc/dynamic_settings.json` (lines 1117, 1128, 1139, 1150, 1845, 1856, 2200, 2211, 2222, 2233, 2504, 2515, 3284, 3520, 3530, 3540) from a select to a numeric field validated against `[min, max]`.
- `src/Model/Source/DefaultOptions.php:172` snaps to the nearest tier today; it becomes a clamp to `[min, max]`.
- **Read the flat `min` / `max` / `default`** on the insurance option — confirmed populated by the API. Not the nested `insured_amount` wrapper, which the spec marks deprecated. PDK still reads the wrapper; that is PDK being behind, and worth a heads-up to that team since the wrapper is slated for removal.
- `src/Setup/UpgradeData.php:946,981,994,1012` calls `getInsurancePossibilities()` inside a **historical data migration**. Freeze the tier lists it needs as private module constants — it must not start depending on a network call.
- `src/Model/Settings/AccountSettings.php` and `Controller/Adminhtml/Settings/CarrierConfigurationImport.php` → contract definitions. Delete the broken imports at `AccountSettings.php:13,15`. Retire the hand-rolled `createArray()` (`@TODO sdk#326` at `CarrierConfigurationImport.php:132`).

**Check:** re-run *Import MyParcel Backoffice settings* and diff the stored JSON against the beta.15 output. Per carrier × zone, confirm `[min, max]` contains every value the old tier list offered — if an old top tier exceeds the contract max, that is a real finding, not a rounding error. An existing saved amount stays valid; an out-of-range one clamps rather than zeroing. Export with an amount that was never a tier (e.g. €137) and confirm the API accepts it. `setup:upgrade` on a pre-migration snapshot still produces identical rows.

### Phase 6 — Shipment building · *Not started*

`src/Model/Sales/TrackTraceHolder.php` → `src/Model/Shipment/ShipmentBuilder`, producing an SDK `Shipment`:

- `ConsignmentFactory::createByCarrierName()` → `(new Shipment())->setCarrier(...)`
- flat address setters → `setRecipient([...])`
- the ~18 option setters → `setOptions(ShipmentOptions)`; `label_description`, `delivery_type`, `delivery_date` and `insurance` all live **inside** options at beta.31
- `setPhysicalProperties(['weight' => …])` — shape unchanged
- `addItem(MyParcelCustomsItem)` → `setCustomsDeclaration(...)`. **`MyParcelCustomsItem::setDescription()`'s 2nd `$carrier` argument is now ignored and max length is hard-coded to 50** — verify no description regressions.
- the pickup block → `setPickup(...)`
- Fix the double-add at `:347-361` / `:367-382`. **The Phase 1 test for this goes green here** — that is the signal the fix landed.
- The API key is no longer on the shipment; pair each `Shipment` with its key for Phase 7.

**Check.** Two layers, neither a golden file. First, the Phase 1 tests still pass **unchanged** — they assert our rules, not the payload, so a correct rewrite should not need to touch them; if one needs editing to go green, that is a behaviour change, so stop and justify it. Second, validate the outbound request against the real spec: `league/openapi-psr7-validator` and `nyholm/psr7` are already dev dependencies and beta.31 ships `openapi/coreapi.yaml`. Caveat — that file is a ~3KB `$ref` root stitching in `common.yaml` and `commonProperties.yaml`, so resolving the bundle is real plumbing. Timebox it; if it fights back, read the serialised payload against the spec by hand once per fixture. Do not add snapshots as a consolation prize.

### Phase 7 — Per-API-key export orchestration · *Not started*

`src/Service/Export/ShipmentExportService` replaces `MagentoCollection::$myParcelCollection` and its 25 call sites. Specification in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).

Touches `MagentoCollection`, `MagentoOrderCollection`, `MagentoShipmentCollection`, `src/Observer/{NewShipment,CreateConceptAfterInvoice}.php`, both `CreateAndPrintMyParcelTrack` controllers, `SendMyParcelReturnMail`.

**Check:** unit tests with a mocked `ShipmentApi` proving N distinct keys ⇒ N create calls; chunking at the configured size including the `1`, `100` and out-of-range-falls-back-to-20 cases; tracks persisted per chunk so a failure in chunk *n* preserves `1..n-1`; one merged PDF. Then the manual two-store and chunking tests below.

### Phase 8 — Fulfilment (PPS) alignment · *Not started*

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` now returns SDK `Model\Shipment\ShipmentOptions`, and `getCarrier()` **throws** unless `setCarrierId()` was called. Update `MagentoOrderCollection::setFulfilment()` (`:163-265`).
- `src/Cron/UpdateStatus.php:126` — loop over the distinct API keys of the orders being polled instead of one ambient key.
- Fix `$orderLines` being created once *outside* the per-order loop at `MagentoOrderCollection.php:166`, so lines accumulate across orders in a multi-order batch.

**Check:** export two orders from two stores in PPS mode; each lands in the right account with only its own order lines; cron updates both.

### Phase 9 — Bump the pin, remove dead code · *Not started*

- `composer.json`: `"myparcelnl/sdk": "11.0.0-beta.31@beta"`, add `"setasign/fpdi": "^2.6"`.
- Delete dead/broken imports: `BaseConsignment` (`MagentoCollection.php:47`), `CarrierFactory` (`MagentoOrderCollection.php:35`, `TrackTraceHolder.php:41`), `CarrierConfigurationFactory` and `CarrierConfiguration` (`AccountSettings.php:13,15`).
- `Model\PrinterlessReturnRequest` constructor is now `(string $apiKey, int $consignmentId)`.
- Carrier `::CONSIGNMENT` constants and `getConsignmentClass()` are gone; `TYPE_B2C`/`TYPE_B2B` moved to `AbstractCarrier`.
- Retire `Tests/Helpers/DeliveryOptionsMocks.php` (dead and SDK-coupled).
- Note in TR-000005 that `AccountWebService`, `CarrierOptionsWebService` and `OrderCollection` are now `@internal`.

**Check:** `composer update myparcelnl/sdk`, then the full verification below.

---

## Traceability: phases ↔ requirements

**The phases are not 1:1 with the FRs, and should not be forced to be.** Phases are ordered by *technical dependency* — constants before value objects before capabilities before shipment building — because that is what keeps every intermediate commit runnable. FRs decompose by *capability*, which cuts across that order. One FR lands over several phases; one phase serves several FRs.

Tracking is this matrix plus each document's own Traceability section (house convention — see BR-000002's). The documents do not reference phases: phases are an artefact of one PR, and the requirements outlive it. This plan is where the two are joined.

| Phase | Implements | Notes |
|---|---|---|
| **Prereq PR** | — (no docs, by decision) | Lands first. Its two hand-off facts go into TR-000007 (hash helper) and TR-000005 (config path) |
| **0** — Plan + requirements | — | Commits this plan, then produces everything below |
| **1** — Tests for our own rules | *supports all* | No FR of its own. Test infrastructure protecting the refactor; inventing an FR would be traceability theatre. Say so in the PR description rather than faking a parent. |
| **2** — Constants and helpers | TR-000005 | Pure refactor, no user-visible capability |
| **3** — Delivery options value objects | TR-000005 | Ditto; guarded by the existing REST conformance tests |
| **4** — Capabilities layer | **FR-000008**, **FR-000010**, TR-000007 | The loose-coupling rules *are* FR-000010's acceptance criteria |
| **5** — Insurance as a range | **FR-000009**, TR-000007 | US-000010 |
| **6** — Shipment building | **FR-000006**, TR-000005 | |
| **7** — Per-key export orchestration | **FR-000006**, **FR-000007**, TR-000006 | US-000007, US-000008, US-000009 |
| **8** — Fulfilment (PPS) alignment | **FR-000006**, **FR-000007** | US-000011 |
| **9** — Bump the pin, remove dead code | BR-000003 | The phase that actually satisfies the business requirement |

Two things this makes visible:

- **Phases 2, 3 and 6 have no FR** — they trace only to a TR. Correct for a like-for-like port: there is no new capability to specify, and an FR asserting "behaviour is unchanged" is not usefully testable.
- **BR-000003 is only satisfied at Phase 9.** Nothing before it delivers the business outcome, so this is one deliverable with nine reviewable steps, not nine shippable increments. Worth stating in the PR description.

If a phase splits — Phase 4 is the likely candidate — split its row rather than letting the mapping go stale.

---

## Verification

The local SDK is a symlink to `app/code/MyParcelNL/Sdk`, so switch versions with `git -C /Applications/MAMP/htdocs/magento246/app/code/MyParcelNL/Sdk checkout v11.0.0-beta.31`.

```bash
# tests (module dir; CI runs this on PHP 8.1–8.4)
composer install --no-interaction
vendor/bin/pest --testsuite=Unit --test-directory=Tests

# Magento rebuild (from the Magento root)
php -dmemory_limit=-1 bin/magento setup:upgrade
php -dmemory_limit=-1 bin/magento setup:di:compile     # catches the BaseConsignment DI break
php -dmemory_limit=-1 bin/magento setup:static-content:deploy
php -dmemory_limit=-1 bin/magento cache:clean
```

Manual end-to-end, on `*.acceptance.myparcel.nl` credentials only — never production:

1. **Single store:** create a shipment from the order view; confirm concept, barcode, track & trace URL, label PDF.
2. **Two stores, two API keys** — the headline case. Select orders from both in the order grid, run the mass action; each shipment lands in the correct MyParcel account and **one merged PDF** downloads containing all labels.
3. **Chunking.** ~50 orders at the default chunk size of 20: three calls, no timeout, all labels present. Then set the size to 1 and to 100 and confirm both work. Then force a failure mid-batch (e.g. an invalid address in chunk 3) and confirm chunks 1–2 stayed recorded on their Magento tracks and the admin is told which orders succeeded.
4. Package types: mailbox, digital stamp, small package, letter.
5. Pickup location, including the carrier-override case that clears an inherited pickup location.
6. ROW destination with customs items — descriptions not truncated differently, items not duplicated.
7. Multicollo (PostNL, NL/BE, package) and return-in-the-box.
8. PPS export mode and the `UpdateStatus` cron across two accounts.
9. A store with **no** API key configured — the clear `LocalizedException`, not an env-var fallback.

---

## Open risks

- **Capability parity (Phases 4–5) is the least certain part**, though the PDK removes most of the design risk. Expect gaps needing an SDK/API answer. Raise them as questions **and** keep moving with a documented assumption recorded in TR-000005 — do not block a phase on an upstream answer, and do not bury the guess either. Where PDK and the OpenAPI spec disagree, trust an observed acceptance response over either.
- **We diverge from the PDK on purpose, in three places:** we reuse `CapabilitiesMapper` instead of porting `hydrateModel()` (DR-3), we do not port `filterSupportedCapabilities()` (FR-000010), and we do not port `InsuranceTierMath` (DR-4). Recorded in TR-000005 so nobody "aligns with the PDK" later without re-reading the argument.
- **Loose coupling has a cost to accept knowingly:** offering an option the account cannot use surfaces as an API error at export time rather than a greyed-out checkbox. That is the intended trade — a clear late failure beats a silent missing feature — but the error must reach the admin legibly rather than being swallowed. Check explicitly in Phase 7.
- **Three SDK defects are raised but not fixed by us.** Until they land, `src/Model/Shipment/Capabilities` carries glue duplicating what `CapabilitiesService` should do, and defect 1 means capabilities is broken for every SDK consumer on beta.25–31 — expect other integrations to hit it.
- The generated `Client\Generated\OrderApi\Model\*` enums the REST transformers bind to are the highest-churn SDK surface; `ShipmentOptionsTransformerTest` asserts `attributeMap()` keys verbatim.
- `MultiColloShipmentService` is pure in-memory and takes **no** API key — do not build it per key. Our capabilities client, by contrast, **is** per key.
- Worth raising an ADR in [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr) for "the Magento module owns its shipment domain layer", since that boundary is now permanent rather than borrowed from the SDK.
