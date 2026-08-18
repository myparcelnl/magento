# SDK v11 Migration Plan

**Status:** Phase 2 complete; Phase 3 next
**Started:** 2026-08-11
**Branch:** `feat/use-sdk-v11-shipments`
**Business Requirement:** [BR-000003 — MyParcel SDK v11 compatibility](../business-requirements/BR-000003-sdk-v11-compatibility.md)

This is the working plan for migrating the module from `myparcelnl/sdk` beta.15 to beta.31. It is a **living document**: it is updated in the same commit as the phase it describes, and when reality diverges from it, the plan changes and says why. The decision records in it are the most valuable part — each one is an assumption that looked right and was not.

---

## Why this migration is happening

The module pins `myparcelnl/sdk: 11.0.0-beta.15@beta`. SDK **beta.22** deleted the entire legacy consignment stack, so the module cannot run on beta.22 or later. The goal is compatibility with **beta.31**, plus re-implementing the multi-API-key ("multi MyParcel store") batch export that the SDK dropped along with `MyParcelCollection`.

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

Beyond shipment creation, the module builds throwaway consignments purely to *ask questions* — the nine probe methods listed in [FR-000008](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md), from `canHaveShipmentOption()` to `isToRowCountry()`. All are gone at beta.31.

This drives the admin *New Shipment* form (`view/adminhtml/templates/new_shipment.phtml`), checkout (`src/Model/Quote/Checkout.php`), 16 insurance virtual types in `etc/di.xml`, and `src/Setup/UpgradeData.php`. beta.31's answers are the **capabilities** and **contract-definitions** endpoints. This is the largest slice of the work, and the one place where behaviour stops being hardcoded and starts being account-specific.

Two `BaseConsignment` usages are **constructor-injected via Magento DI** (`src/Block/Sales/OrderAction.php:41`, `src/Block/Sales/ShipmentAction.php:48`) with no `di.xml` entry — invisible to an XML grep, and they break `setup:di:compile`.

### The PDK is the reference implementation

`myparcelnl/pdk` has already done this migration: v4.7.1 requires `myparcelnl/sdk: ^11.0.0-beta.30@beta`. Read it before designing anything in Phases 4 or 5. It settles the client shape and the caching split, and it carries at least one requirement documented nowhere else (the V2 `Accept` header — specified in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md)).

We deviate from it deliberately in three places, all recorded below.

---

## Decision records

Corrections made while planning. Each was wrong in a way that is easy to repeat.

### DR-1: Capabilities are per-account, not store-agnostic

**Initially assumed:** Phase 4 needs no API key, because `CapabilitiesService::__construct` takes none.

**Wrong because:** capabilities differ per MyParcel account — different carrier contracts give different options. The layer must be API-key scoped. The key is not absent from the request, merely unreachable: `Sdk\Services\Capabilities\HttpCapabilitiesClient` hardcodes `ShipmentApiFactory::make(null, …)`, so the key cannot be supplied at all. That factory's environment fallback is the wrong-account hazard specified as defect 2 in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

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

**Wrong because:** the write (`CarrierConfigurationImport.php:70-71`) and the read (`AccountSettings.php:32`) are *both* default-scope. The API key in the path is the discriminator and it works as designed. Only the API key *lookup* is scope-aware, which is correct. Two narrower real items replaced it: `PackageRepository` read the key without an explicit store id (fixed in Phase 2 by `Package::setStoreId()`), and `AccountSettings.php:13,15` import classes absent from beta.15 and beta.31.

### DR-7: `getAgeCheck()`'s product-attribute and carrier-default tiers are dead code

**Found while writing Phase 1 tests.** `TrackTraceHolder::getAgeCheck()` documents a 3-tier precedence — explicit option, then product attribute, then carrier default — mirroring `ShipmentOptions::hasAgeCheck()`. The two do not actually agree.

**Wrong because:** `getAgeCheck()` calls `ShipmentOptions::getAgeCheckFromProduct($magentoTrack)`, passing the `Track` model itself rather than its items (contrast `hasAgeCheck()`, which correctly passes `$this->order->getItems()`). `getAgeCheckFromProduct()` does `foreach ($products as $product)` to read each product's age-check attribute. `Track` has no `Iterator`/`IteratorAggregate` anywhere in its inheritance chain (`AbstractModel` → `AbstractExtensibleModel` → `AbstractModel` → `DataObject`, all properties `protected`), so PHP's default object iteration exposes zero properties and the loop runs zero times. The function's initialized default, `false`, comes back unconditionally — never `null`. Because the precedence chain is `$ageCheckFromOptions ?? $ageCheckOfProduct ?? $ageCheckFromSettings` and `??` only falls through on `null`, the carrier-default tier can never be reached either. Only the explicit-option tier is reachable today.

**Treated the same as the customs double-add** (Phase 1's test asserts the documented precedence and marks the two unreachable-tier cases `->todo()`; the fix lands in Phase 6, alongside the `ShipmentBuilder` rewrite that also fixes the double-add).

### DR-8: Splitting capabilities into a second PR was considered and rejected

**Proposed:** defer Phases 4 and 5 to a follow-up PR that would introduce capabilities everywhere, settings included. This PR would still move the pin, ending knowingly broken, and merge to an integration branch rather than to `develop`.

**Rejected because it saves no work and costs coordination.** Three things settled it:

- **There is no small capabilities subset.** Every broken consumer needs the same client: the admin form (`getAllowedPackageTypes`, `getAllowedShipmentOptions`, `canHaveShipmentOption`) and checkout (`canHaveDeliveryType`, `canHavePackageType`, `canHaveExtraOption`) all go through it. To ship a module that works at beta.31 you need substantially all of Phase 4, so deferring moves the work rather than reducing it.
- **The first PR could not ship.** Splitting a PR pays off when the first half reaches `develop`. A module whose admin form and checkout fatal cannot, so it would sit on an integration branch waiting for the second PR — which voids the risk-isolation argument the split was built on. Nothing would reach `develop` any sooner.
- **It adds work that would not otherwise exist:** an expected-failure list to produce and reconcile on every CI run, an integration branch to rebase against `develop` drift, and deferred verification — a defect introduced by Phase 2, 3 or 6 in the form or checkout paths would surface during the second PR, far from its cause.

**Kept from the exercise:** the two carve-outs recorded in Phases 2 and 8 below, which belong there on their own merits, and the `canUseMultiCollo()` retype in Phase 6, which was unassigned.

If this is reconsidered, the workable seam is the *feature* line, not the capabilities line: compatibility (including the capabilities layer) in one PR, insurance-as-a-range and contract definitions in a second. Both would ship.

### DR-9: The EU country list changes membership, and that is the point

**Found while writing Phase 2.** The two lists are not the same list.

`AbstractConsignment::EURO_COUNTRIES` at beta.15 holds **XK** (Kosovo) and omits **MT** (Malta). `Services\CountryCodes::EU_COUNTRIES` — which survives at beta.31, and is byte-identical at beta.15 — holds MT and omits XK. beta.31's own `Concerns\HasCountry::isToEuCountry()` already reads the new list, so the SDK has made this correction internally.

**Decision: adopt the beta.31 list.** Malta is in the EU and Kosovo is not, so the old list was simply wrong. Effect at the three call sites — `MagentoOrderCollection.php:241`, `src/Model/Source/DefaultOptions.php:153`, `src/Helper/ShipmentOptions.php:301` (the module's helper, not the SDK's class of the same name): a Malta shipment stops getting a customs declaration and drops to the EU insurance tier; a Kosovo shipment starts getting a customs declaration and moves to the ROW tier. Both are corrections, and both are visible to merchants shipping to those two countries.

**This is an exception to TR-000005**, which requires every new constant to equal the beta.15 value it replaces. The requirement is amended there rather than quietly broken here. `Tests/Unit/Model/Shipment/ConstantEquivalenceTest.php` asserts the MT/XK delta head-on, so the difference is a pinned decision rather than a drift.

### DR-10: The per-country print position rule is removed, not fixed

**Two wrong readings, corrected by testing on a real install.**

`src/Block/Sales/{OrderAction,ShipmentAction}.php` each took a DI-injected `BaseConsignment` purely to call `isToRowCountry()` on it, feeding `print_position` in `order_view_action.phtml` and `shipment_view_action.phtml`. Magento constructs that consignment with no arguments, so its country stays null and the method returned `true` for every order — verified by running it.

**First reading, wrong:** that the print position selector had therefore never appeared, and wiring the method to the order's real country would fix it. **Second reading, also wrong:** that a manual check would confirm the fix.

**What the install showed.** The selector had always been visible, because the page that matters does not use this logic at all. The order **grid** (`sales_order_index` → `order.phtml`) hardcodes `"print_position": true`, and it must: one `x-magento-init` serves a mass action over many orders with different destinations, so no single country exists to test. Only the two single-entity views ever consulted the country, which means the same ROW order offered position selection from the grid and withheld it from its own detail page.

**Decision: remove the rule.** Both templates now emit `"print_position": true`, matching the grid, and `isToRowCountry()` is deleted from both blocks along with the `CountryCode` import it needed. Behaviour is unchanged from what merchants have always seen, and the inconsistency between the two entry points is gone.

**The part of this that mattered still stands.** Deleting `isToRowCountry()` removes the only reason those constructors took a `BaseConsignment`, so the argument goes with it — and that argument was the only thing breaking `setup:di:compile` at beta.31. The outcome is cleaner than the original plan: the parameter is unambiguously dead rather than replaced by a live call.

`getCountry()` stays on both blocks. It is now unused internally, but it is public API on a block whose template merchants override, and removing it buys nothing.

If ROW shipments should skip position selection, it belongs where the decision is actually made — per shipment at print time, not per page — and it needs the grid case answered first. Not in scope here.

### DR-11: Address validation is removed, not ported

**Decided in review, and it is a feature removal rather than a port** — the first in this branch.

`Sdk\Helper\ValidatePostalCode` is deleted at beta.22, so it had to be replaced or dropped. `Helper\ValidateStreet` survives at beta.31 and was under no such pressure. Both are gone.

**Why both.** Keeping one and dropping the other leaves the module half-validating: a bad street named per order before export, a bad postcode discovered only when the API rejects it. Either the module pre-flights addresses or the API is the authority. It is now the API.

**What disappears.** `Controller\Adminhtml\Order\CreateAndPrintMyParcelTrack::filterCorrectAddress()` existed only to run these two checks and is deleted whole, so a malformed address no longer drops its order out of a mass-action batch with a named message. The two blocks in `SaveOrderBeforeSalesModelQuoteObserver` were also **the only writers of the warning text into `track_status`**, so a new order no longer shows "⚠️ Please check street" or "Please check postal code" in the order grid.

**What is unaffected**, checked rather than assumed: `Observer/NewShipment.php:188` and `MagentoOrderCollection.php:324` still write the export status into the same column, and `Cron/UpdateStatus.php:121` filters on `ORDER_STATUS_EXPORTED`, which was never the warning value.

The `$order->getShippingAddress() === null` early return in the observer stays. Nothing below it reads the address any more, but it still gates the whole observer for orders that have none, and removing it would be a second behaviour change smuggled in with the first.

### DR-12: No silent defaults — read tolerantly, write strictly, never substitute

**Raised in review**, against the fallbacks added earlier in this same phase.

A value we do not understand must never be quietly replaced with one we do. Accept it, keep it, try to send it, and if it cannot be sent, tell the admin so they can correct it. A visible late failure beats a silent wrong shipment. This is the same trade the Open Risks section already records for capabilities; DR-12 states it as a rule and applies it to stored values as well.

**The SDK is asymmetric, deliberately.** `Support\EnumFallback` says so: the generated `ObjectSerializer` passes an unknown enum value through unchanged on the read path so a new API value never breaks parsing, while request serialization stays strict and throws. So the SDK can hand us a value it will then refuse to send back.

**Corrected after a first, wrong reading.** It was recorded here that shipment create could never send a type the SDK's id map does not know, and an SDK issue was queued to ask for it. That is true at beta.15 and false at beta.31 — tested at both tags, in the table at [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md).

beta.29's "enum validation loosening" — the second BREAKING flag in the whole range — removed the enum checks from the generated setters, which is the same change that introduced `EnumFallback`. At beta.31 `RefShipmentShipmentOptions` rejects only null. So an unknown **id** reaches the API and the API answers for it, which is exactly the behaviour this rule wants. The SDK issue was withdrawn.

The one asymmetry that remains is names, not ids: `ShipmentOptions::setPackageType('pallet_xl')` still throws locally, because the string path resolves through the mapping while an int bypasses it. Both paths still end in a named error rather than a substitution, one raised by the SDK and one by the API.

**The live bug this exposed**, verified end to end:

```
AbstractDeliveryOptionsAdapter::getDeliveryTypeId()   // DELIVERY_TYPES_NAMES_IDS_MAP[$x] ?? null
  → null for 'early_morning'
TrackTraceHolder.php:184  ->setDeliveryType($adapter->getDeliveryTypeId() ?? DeliveryType::STANDARD)
  → shipment created and paid for as standard, no error anywhere
```

**Applied in Phase 2:** both facades gained `nameFromIdOrNull()` beside the throwing `nameFromId()`, so read paths degrade rather than fatal while `toId()` stays strict for outbound. Both call sites distinguish "unrecognised" from "absent" and stay quiet for the latter, so the log means something.

`NewShipment::getDeliveryType()` goes further and **substitutes nothing**: it returns `?int`, null for a value it cannot resolve. Its only caller gates the receipt code option, which is standard-only — so the old fallback to `standard` did not merely hide the discrepancy, it offered an option there was no reason to believe applied. Null fails closed. `DefaultOptions` gained `getPackageTypeName(): ?string`, which returns the stored value verbatim — no resolution, no fallback, no log — and the New Shipment form now uses that instead of the resolved id. An unrecognised type reaches the form as itself and pre-selects nothing, rather than pre-selecting a package type the customer never chose. `getPackageType(): int` keeps substituting, because two callers use it as an id fallback (`TrackTraceHolder.php:471,475`, `DeliveryCosts.php:79`) and `"php": "^7.4 || ^8.0"` rules out an `int|string` return type; that one waits for Phase 3's value object.

One consequence to keep in mind when Phase 3 widens this further: the stored value originates in checkout delivery options and is therefore customer-influenced. `new_shipment.phtml` did no escaping at all, which was safe only while it emitted our own hardcoded names into its `x-magento-init` JSON. It now passes the value through `$escaper->escapeJs()`. Any further raw value routed into that template needs the same treatment.

Worth knowing the module already displays unknown delivery types correctly where it displays them at all — `Block/Sales/View.php:81` echoes the stored value raw and `Model/Carrier/Carrier.php:221` falls back to the raw value as its title. The graceful pattern exists; it was the int-typed resolution points that broke it.

**Deferred to the phases that own the code:** Phase 3 keeps a stored name verbatim rather than mapping an unknown one to null, and Phase 6's `ShipmentBuilder` refuses to substitute at all — an unresolvable type fails its own shipment with a message naming the order and the value, leaving the rest of the batch intact. Added to FR-000010 as an acceptance criterion.

---

## Standing decisions

- **Git:** one PR on `feat/use-sdk-v11-shipments`, **one commit per phase**. The composer bump is the last code commit.
- **Multi-account label PDF:** merge into a single PDF in Magento via `setasign/fpdi`, preserving today's UX, and make fpdi an explicit module dependency. Specified in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- **Carrier options:** migrate to contract definitions now, not deferred. The SDK warns this is not a 1:1 replacement for the `CarrierOptions` model; consumers read the generated contract-definition models directly.
- **Latent multi-store bugs in scope:** cron PPS status polling (`src/Cron/UpdateStatus.php:126` polls one key only) and return labels using the wrong key.
- **We do not touch the SDK.** No PR, no `vendor/` patch (per `CLAUDE.md`). Three defects are raised as issues; the module works around them.

---

## Prerequisite: separate PR, lands before this one

Built on the fly, with **no requirement documents** — small, self-contained, rationale lives in its PR description.

**Naming note:** the path is `myparcelnl_magento_general/account_settings_{apiKey}`, not `account_data_`. Only two call sites exist — `src/Model/Settings/AccountSettings.php:32` (read) and `Controller/Adminhtml/Settings/CarrierConfigurationImport.php:70-71` (write) — so the change is contained, but a cleanup routine must match the real prefix or it will run clean and delete nothing, which looks like success.

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
- `src/Service/TrackTraceUrl.php` — provisional: Phase 7 replaces its hard-coded base URL with the API's own links (TR-000005)

Every new class gets a class-level doc block explaining responsibility and invariants (per `CLAUDE.md`).

---

## Phases

### Phase 0 — Commit this plan, then the requirements documents · *Complete*

This document is the first commit of the PR. It is canonical from the moment it lands; the harness plan file is scratch from then on.

Then create, following `docs/templates/` and matching BR-000002's depth: BR-000003, FR-000006 through FR-000010, TR-000005 through TR-000007, and US-000007 through US-000011. See the traceability matrix below.

**Check:** every document's Traceability / Parent Requirement section resolves, and the matrix has no phase without a requirement and no requirement without a phase. Use the `ai-basekit:orchestrator` agent to generate the matrix and run an ecosystem health check rather than eyeballing links.

### Phase 1 — Tests for the rules that are *ours* · *Complete*

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
- Same treatment for a second pre-existing bug found while writing this phase: `getAgeCheck()`'s product-attribute and carrier-default tiers are unreachable (DR-7). Assert the documented 3-tier precedence, mark the two unreachable-tier cases `->todo()`, goes green in Phase 6 alongside the double-add.

**Check:** `vendor/bin/pest` green bar the one documented expected-fail. If a test's expected value depends on *which carrier* or *which country*, it belongs in Phase 4.

### Phase 2 — Module-owned constants and small helpers · *Complete*

- `AbstractConsignment::{CC_*, PACKAGE_TYPE_*, PACKAGE_TYPES_*_MAP, DELIVERY_TYPE_*, SHIPMENT_OPTION_*, EURO_COUNTRIES, …}` → `src/Model/Shipment/{CountryCode,PackageType,DeliveryType,ShipmentOption}`.
- `Sdk\Helper\TrackTraceUrl` → `src/Service/TrackTraceUrl` (used at `src/Ui/Component/Listing/Column/TrackAndTrace.php:118` and `src/Block/DataProviders/Email/Shipment/TrackingUrl.php:32,61` — de-duplicate that repeated call).
- `Sdk\Helper\ValidatePostalCode` is **deleted, not ported** — address validation is removed whole, see DR-11.
- Validate our own **outbound** enums here, per the read-leniently / write-strictly rule in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).
- **Drop the `BaseConsignment` constructor argument** from `src/Block/Sales/OrderAction.php:41` and `src/Block/Sales/ShipmentAction.php:48` (moved here from Phase 4). These two arguments are the only thing that breaks `setup:di:compile` at beta.31, and fixing them here keeps `di:compile` green from Phase 2 onward instead of from Phase 4. The `isToRowCountry()` they fed is deleted rather than rewired — see DR-10.

`Services\CountryCodes` and `Support\{Str,Collection}` survive in beta.31 — leave them alone. So does `Helper\SplitStreet`, verified against the tag: beta.31's `src/Helper/` keeps only `MyParcelCurl`, `RequestError`, `SplitStreet` and `ValidateStreet`. `ValidateStreet` survives too but is no longer used — see DR-11.

**Absorbed into this phase during review:**

- All seven SDK package and delivery types are named, not five (DR-12's worked example is why). Verified inert: nothing iterates the lists, both admin lists are hardcoded arrays, and an empty weight lookup for a pallet still returns 0.
- Address validation removed entirely (DR-11).
- Store scoping moved from a `fitInMailbox()` parameter onto `Package::setStoreId()`, so all ten config reads in `PackageRepository` resolve against one store instead of one read being scoped and nine ambient. `Checkout` sets it once in its constructor, because the settings setters read config and a store set after them would apply to nothing.
- 17 unused imports removed, including five from the Phase 9 dead-import list.

**Two things the plan got slightly wrong, corrected while doing it.**

- **The names are ours, only the ids are the SDK's.** Delegating name↔id translation wholesale to `Model\Shipment\{PackageType,Carrier}` would have changed the vocabulary: the SDK's v2 enums call `letter` `UNFRANKED`, `package_small` `SMALL_PACKAGE`, and `standard` `STANDARD_DELIVERY`. Those snake_case names are persisted in `core_config_data`, in the order's delivery-options JSON and in the REST v1 contract, so they cannot follow the SDK. The facades therefore source **ids** from the generated `RefShipmentPackageType` / `RefTypesDeliveryType` — present and identical at beta.15 and beta.31 — and keep the module's own names. Translation to v2 names happens at the boundary, as `PackageTypeTransformer::LEGACY_NAME_MAP` already does for the REST layer. Phase 6 needs the same translation on the outbound shipment path.
- **`Mapping\DeliveryTypeApiMapping` does not exist at beta.15**, only at beta.31, so `DeliveryType` cannot delegate to it while the branch runs on the installed SDK. It reads the generated ref models directly instead, which works at both versions.

**Check.** `vendor/bin/pest` green. `Tests/Unit/Model/Shipment/ConstantEquivalenceTest.php` asserts every new constant equals the beta.15 SDK value, with `EURO_COUNTRIES` as the one documented exception (DR-9). `setup:di:compile` succeeds. Grep shows zero remaining `AbstractConsignment::` **constant** references — the class itself survives in type hints owned by Phases 4, 6 and 7 (`MagentoCollection`, `NewShipment::consignmentHasShipmentOption()`, `NewShipmentForm::getCarrierSpecificAbstractConsignments()`, `new_shipment.phtml`), and in the equivalence test, which Phase 9 deletes:

```bash
grep -rnE "AbstractConsignment::[A-Z_]" --include="*.php" --include="*.phtml" src Controller view Tests \
  | grep -v ConstantEquivalenceTest
```

The character class matters. A bare `AbstractConsignment::` also matches `AbstractConsignment::class`, which is a type reference rather than a constant and stays legitimately in place — `Tests/Unit/Block/Sales/NewShipmentDeliveryTypeTest.php:60` mocks it. Constants are uppercase and static methods are camelCase, so `[A-Z_]` selects exactly the references this check is about.

Note `setup:di:compile` cannot run while the module has its own `vendor/` from `composer install`: Magento scans `app/code/**` and hits a `phpstan/phpdoc-parser` collision with the Magento root vendor. Move the module `vendor/` outside `app/code` for the duration, or run the check on an install without it.

**Verification state at close.** `vendor/bin/pest` green — 298 passed, 2 todos, 0 failures; the two todos are the documented pre-existing bugs that go green in Phase 6. The constant grep returns zero. `ConstantEquivalenceTest` passes with the `EURO_COUNTRIES` exception asserted head-on. `setup:di:compile` is the one check that needs a vendor-free install, so confirm it there before the branch merges — the DR-10 change it guards is the removal of the two `BaseConsignment` constructor arguments, both verified absent by grep.

### Phase 3 — Module-owned delivery options value objects · *Not started*

Replace `Sdk\Adapter\DeliveryOptions\*` and `Factory\DeliveryOptionsAdapterFactory` (13 files) with module-owned immutable value objects and a factory. `src/Adapter/DeliveryOptionsFromOrderAdapter.php` and `ShipmentOptionsFromAdapter.php` currently extend SDK abstracts and write their protected properties directly; that coupling disappears here.

Touches `src/Model/Quote/Checkout.php`, `src/Model/Checkout/ShippingMethods.php`, `src/Service/NeedsQuoteProps.php`, `src/Model/Carrier/Carrier.php`, `src/Block/Sales/View.php`, `src/Model/Rest/{OrderDeliveryOptions.php, Request/OrderDeliveryOptionsV1Request.php, Transformer/{ShipmentOptionsTransformer,PickupLocationTransformer}.php}`, `src/Plugin/Magento/Sales/Api/Data/OrderInformationUpdate.php`, `src/Model/Source/DefaultOptions.php`, `Tests/Helpers/DeliveryOptionsMocks.php`.

**Carry an unrecognised type through as a value, do not reduce it to null** (DR-12). This is the phase that makes the rule enforceable, because it owns the types.

`AbstractDeliveryOptionsAdapter::getDeliveryTypeId()` returns null for a name it does not know, and `DefaultOptions::getPackageType()` / `NewShipment::getDeliveryType()` are typed `int`, so today an unknown value has nowhere to live and gets replaced with a default. Phase 2 could only log that; a value that can hold the unknown is what actually fixes it.

Give package type and delivery type a small value object holding **either a resolved id or the raw input**:

- `isKnown()` — whether it resolved.
- a **label** used everywhere a type is displayed. For an unresolved value it renders the value itself — `Package type 31`, `Delivery type pallet_xl` — so an admin sees what the order actually carries instead of a plausible wrong answer. Nothing may show a default in its place.
- `toApiValue()` — the id for a known type, and for an unknown one the raw value if it is numeric, since **beta.31 serializes an unknown id unchanged** and lets the API judge it. A non-numeric unknown cannot be sent and throws here, naming the value.

The consequence to design for, not around: an order carrying an unknown type stays exportable right up to the API call, and fails there with the API's own message. That is the intended outcome — the merchant learns the type is unsupported from the party that decides it, and the value is never quietly changed on the way.

Return types widen accordingly (`getPackageType()`, `getDeliveryType()`), which reaches `new_shipment.phtml`, `Checkout` and `DefaultOptions`. Phase 2's logging in `DefaultOptions::getPackageType()` and `NewShipment::getDeliveryType()` is a stopgap and is removed here.

**Check:** `Tests/Unit/Model/Rest/OpenApiConformanceTest.php` and the transformer tests stay green — the versioned REST response must be byte-identical.

### Phase 4 — Capabilities layer · *Not started*

`src/Model/Shipment/Capabilities`: module-owned, API-key-scoped, cached. Calls `postCapabilities` directly (DR-1, DR-2). Full specification in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

Touches `view/adminhtml/templates/new_shipment.phtml` (heaviest), `src/Block/Sales/{NewShipment,NewShipmentForm}.php`, `src/Model/Quote/Checkout.php`, `src/Helper/CustomsDeclarationFromOrder.php`.

**Dissolve the fixed type lists here.** `PackageType::IDS` / `NAMES` and the `DeliveryType` equivalents are transitional: "which types exist" becomes what capabilities reports per account. What survives permanently is the *name* map, because the module's snake_case names are `core_config_data` path segments, sit in every historical order's delivery-options JSON, and are the checkout widget's protocol — none of which we control. The list is not an allow-list and must not become one (FR-000010). This is also where the v2→module name translation lands; `PackageTypeTransformer::LEGACY_NAME_MAP` should then read from it rather than holding a second copy.

**Two items moved out of this phase.** Dropping the `BaseConsignment` DI argument from `src/Block/Sales/{OrderAction,ShipmentAction}.php` is now **Phase 2**; re-sourcing `getLocalCountryCode()` at `MagentoOrderCollection.php:425` is now **Phase 8**. Neither is capability data — the first is a dead constructor argument, the second a static country fact that TR-000005 routes to module constants — and doing them earlier keeps `di:compile` and the PPS path healthy through more of the branch.

**SDK issues to raise — manual, this phase.** Three separate issues on `myparcelnl/sdk`:

| # | Issue | Severity | Notes for the write-up |
|---|---|---|---|
| 1 | `HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | **Broken for every consumer on beta.25–31** | The user-agent string passes the null/empty-array guard and is `jsonEncode`d into the body as a bare JSON string, while the request model goes to `ObjectSerializer::toHeaderValue()`. Valid JSON of the wrong shape, so a confusing API-side 4xx rather than a clear local error. Ask for a regression test exercising `HttpCapabilitiesClient` itself — the coverage gap is why it shipped. |
| 2 | `HttpCapabilitiesClient` / `CapabilitiesService` accept no API key, **and** three factories silently fall back to `getenv` | Blocks all library use; the fallback is a wrong-account hazard for every consumer | Two asks in one issue. First, an appended optional constructor arg on both (non-breaking). Second — the more serious one, and the one to lead with — the three factories' environment fallback. Both the argument and the two-line patch are written out in TR-000007's defect 2; copy them from there rather than restating. |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` drops all option values | Design question, not a bug | Propose rather than prescribe: `CapabilitiesResponse` is `final` with a 6-arg positional constructor the SDK's own test calls positionally, so adding insurance means a 7th positional arg or exposing the raw models. Note our client is close to the latter — that may speed agreement. |

Reference `UPGRADE.md`'s claim that `CapabilitiesService` is the v11 answer for capabilities, since 1–3 together make it untrue in practice. When they land, delete the workaround behind its `@todo` and reassess whether this layer can shrink.

**Check:** `setup:di:compile` succeeds. The admin New Shipment form renders the same package types, delivery types and shipment options as on beta.15, per carrier (insurance changes shape in Phase 5). Checkout delivery options unchanged. A cold checkout makes at most one capabilities call per (account, request shape); a warm one makes none.

### Phase 5 — Insurance as a range, contract definitions, account settings · *Not started*

Insurance becomes a free amount between `min` and `max` (DR-4). Specification in [FR-000009](../functional-requirements/FR-000009-insurance-as-a-range.md) and [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

- `src/Model/Source/CarrierInsurancePossibilities.php` and the **17 virtual types at `etc/di.xml:75-196`** exist only to populate tier dropdowns, so they go. Only 16 of them are referenced by a setting, so one carrier-and-zone virtual type is already orphaned — identify which while removing them, since it may mean a missing setting rather than a dead type. Convert the 16 `source_model` references in `etc/dynamic_settings.json` (lines 1117, 1128, 1139, 1150, 1845, 1856, 2200, 2211, 2222, 2233, 2504, 2515, 3284, 3520, 3530, 3540) from a select to a numeric field validated against `[min, max]`.
- `DefaultOptions::getInsurance()` (`src/Model/Source/DefaultOptions.php:163`) snaps to the nearest tier today; it becomes a clamp to `[min, max]`.
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
- Fix the double-add at `:347-361` / `:367-382`. **The Phase 1 test for this goes green here** — that is the signal the fix landed. Same for the age-check precedence bug (DR-7).
- The API key is no longer on the shipment; pair each `Shipment` with its key for Phase 7.
- **Never substitute a type we cannot resolve** (DR-12). If a stored delivery or package type has no id, fail that shipment with a message naming the order and the value, and leave the rest of the batch intact — the per-chunk persistence Phase 7 specifies. Silently exporting a different delivery than the customer paid for is the outcome this phase must make impossible.
- `isToRowCountry()` at `:339` comes from the Phase 2 `CountryCode` constants, not from capabilities — it is a static country fact (TR-000005).
- **Retype `canUseMultiCollo()`** (`MagentoCollection.php:643`, called from `MagentoCollection.php:439` and `src/Observer/NewShipment.php:111`). It is typed `AbstractConsignment` and receives what this phase turns into a `Shipment`, so it breaks here whatever else happens. The body is module-owned carrier logic; Phase 4 replaces the rule itself with the capabilities collo maximum, so keep the retype mechanical and leave the rule alone.

**Check.** Two layers, neither a golden file. First, the Phase 1 tests still pass **unchanged** — they assert our rules, not the payload, so a correct rewrite should not need to touch them; if one needs editing to go green, that is a behaviour change, so stop and justify it. Second, validate the outbound request against the real spec: `league/openapi-psr7-validator` and `nyholm/psr7` are already dev dependencies and beta.31 ships `openapi/coreapi.yaml`. Caveat — that file is a ~3KB `$ref` root stitching in `common.yaml` and `commonProperties.yaml`, so resolving the bundle is real plumbing. Timebox it; if it fights back, read the serialised payload against the spec by hand once per fixture. Do not add snapshots as a consolation prize.

### Phase 7 — Per-API-key export orchestration · *Not started*

`src/Service/Export/ShipmentExportService` replaces `MagentoCollection::$myParcelCollection` and its 25 call sites. Specification in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).

Touches `MagentoCollection`, `MagentoOrderCollection`, `MagentoShipmentCollection`, `src/Observer/{NewShipment,CreateConceptAfterInvoice}.php`, both `CreateAndPrintMyParcelTrack` controllers, `SendMyParcelReturnMail`.

**Switch track & trace links to the API's own.** `src/Service/TrackTraceUrl` currently builds the URL from a hard-coded `https://myparcel.me/track-trace/` base, carried over from the deleted SDK helper. beta.31 returns the authoritative links per shipment as `link_consumer_portal` and `link_tracktrace` on `ShipmentDefsTrackTrace`, requested via a `link_consumer_portal` flag on `getShipmentsById` and reachable through `Services\TrackTrace\ShipmentTrackTraceService::fetchTrackTraceData()`. Checked at the tag: there is **no** account setting carrying a base URL, so this is the only way to stop hard-coding it. Not a drop-in — `src/Ui/Component/Listing/Column/TrackAndTrace.php:118` renders one link per grid row and needs a batched fetch keyed by shipment id plus caching, and `src/Block/DataProviders/Email/Shipment/TrackingUrl.php` should store the link at export time rather than fetch it while rendering an email.

**Check:** unit tests with a mocked `ShipmentApi` proving N distinct keys ⇒ N create calls, and — the inverse, since grouping is by resolved key value rather than by store — several stores inheriting one key ⇒ a single call; chunking at the configured size including the `1`, `100` and out-of-range-falls-back-to-20 cases; tracks persisted per chunk so a failure in chunk *n* preserves `1..n-1`; one merged PDF. Then the manual two-store and chunking tests below.

### Phase 8 — Fulfilment (PPS) alignment · *Not started*

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` now returns SDK `Model\Shipment\ShipmentOptions`, and `getCarrier()` **throws** unless `setCarrierId()` was called. Update `MagentoOrderCollection::setFulfilment()` (`:163-265`).
- `src/Cron/UpdateStatus.php:126` — loop over the distinct API keys of the orders being polled instead of one ambient key.
- Fix `$orderLines` being created once *outside* the per-order loop at `MagentoOrderCollection.php:166`, so lines accumulate across orders in a multi-order batch.
- **Re-source `getLocalCountryCode()`** at `MagentoOrderCollection.php:425-432` from the Phase 2 `CountryCode` constants, and delete the throwaway consignment at `:425` that exists only to read it (moved here from Phase 4). `SplitStreet::splitStreet()` itself survives at beta.31; only its second argument needs a new source. It sits here rather than in Phase 4 because `setShippingRecipient()` is reached only from `setFulfilment()`, which this phase owns.

**Check:** export two orders from two stores in PPS mode; each lands in the right account with only its own order lines; cron updates both.

### Phase 9 — Bump the pin, remove dead code · *Not started*

- `composer.json`: `"myparcelnl/sdk": "11.0.0-beta.31@beta"`, add `"setasign/fpdi": "^2.6"`.
- ~~Delete dead/broken imports: `BaseConsignment`, `CarrierFactory` ×2, `CarrierConfigurationFactory`, `CarrierConfiguration`.~~ **Done in Phase 2**, along with twelve other unused imports found in the same sweep.
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

- **Phases 2 and 3 have no FR** — they trace only to TR-000005. Correct for a like-for-like port: there is no new capability to specify, and an FR asserting "behaviour is unchanged" is not usefully testable.
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
10. **Address validation is gone (DR-11).** Place an order with a malformed NL postcode and street. It is accepted, no warning shows in the order grid, and exporting it surfaces the API's rejection legibly rather than silently.
11. **Store-scoped package settings (Phase 2).** Two stores with different mailbox, digital stamp and package small settings; confirm checkout in each resolves its own store's values, not the other's.

---

## Open risks

- **Capability parity (Phases 4–5) is the least certain part**, though the PDK removes most of the design risk. Expect gaps needing an SDK/API answer. Raise them as questions **and** keep moving with a documented assumption recorded in TR-000005 — do not block a phase on an upstream answer, and do not bury the guess either. Where PDK and the OpenAPI spec disagree, trust an observed acceptance response over either.
- **We diverge from the PDK on purpose, in three places** — DR-3, DR-4 and FR-000010 each own one. Enumerated with their reasoning in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md), so nobody "aligns with the PDK" later without re-reading the argument.
- **Loose coupling has a cost to accept knowingly**, stated in [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md): the API error at export replaces the greyed-out checkbox, and the trade only holds while that error reaches the admin legibly. Check explicitly in Phase 7.
- **Three SDK defects are raised in Phase 4 and are not fixed by us.** Until they land, `src/Model/Shipment/Capabilities` carries glue duplicating what `CapabilitiesService` should do, and defect 1 means capabilities is broken for every SDK consumer on beta.25–31 — expect other integrations to hit it.
- The generated `Client\Generated\OrderApi\Model\*` enums the REST transformers bind to are the highest-churn SDK surface; `ShipmentOptionsTransformerTest` asserts `attributeMap()` keys verbatim.
- `MultiColloShipmentService` takes no API key while our capabilities client is per key — the asymmetry that makes per-key client construction easy to get wrong. Rule in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- Worth raising an ADR in [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr) for "the Magento module owns its shipment domain layer", since that boundary is now permanent rather than borrowed from the SDK.
