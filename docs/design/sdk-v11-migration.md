# SDK v11 Migration Plan

**Status:** Phase 6b complete and the pin is on **beta.31** (DR-30); Phase 6c next
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
| The v11 stack (`Model\Shipment\Shipment`, `Collection\ShipmentCollection`, `Services\Shipment\*`) **already exists at beta.15**. `Shipment.php` and `ShipmentCollection.php` are byte-identical to beta.31; **the rest of the stack is not** — see DR-21. | **The whole module can be migrated against the currently-installed SDK, bumping the pin last**, provided it writes to beta.15's stricter surface. Every intermediate commit stays runnable. |
| `vendor/myparcelnl/sdk` is a **symlink** to `app/code/MyParcelNL/Sdk`, a git checkout currently at **beta.15**, matching the pin exactly. | Local verification is a `git checkout` in that repo — switch versions there to check behaviour at a different beta. |
| The SDK's `UPGRADE.md` at beta.31 is an authoritative before/after migration guide. | Primary reference — read it, do not re-derive it. |
| **54 module files** touch the SDK; **34** touch classes beta.31 removed. | Broad but mostly shallow. |
| `AbstractConsignment` usage is **~41 constants / 120+ usages** and only ~7 real type usages. | The largest slice is mechanical constant replacement. |
| `Shipment` has **no `setApiKey()`**; the seven per-account services take the key as their **first constructor argument** and are `final`. Not immutable — `ShipmentLabelsService` caches `$labelPdf`, and all inherit the mutable `HasUserAgent` trait. `MultiColloShipmentService` has no constructor and `CapabilitiesService` takes no key. | Grouping by API key is now entirely the consumer's job. |
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

**Wrong because:** the write and the read are *both* default-scope. The API key in the path is the discriminator and it works as designed. Only the API key *lookup* is scope-aware, which is correct. **#967 confirmed this rather than changing it** — the fingerprinted row is written at default scope only, whatever scope a legacy row sat at. Its call sites moved in that PR: the read is `AccountSettings.php:36`, and the write left the controller for `Service\AccountSettings\Importer`. Two narrower real items replaced it: `PackageRepository` read the key without an explicit store id (fixed in Phase 2 by `Package::setStoreId()`), and `AccountSettings.php:13,15` import classes absent from beta.15 and beta.31.

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

### DR-13: The SDK's name-to-id maps were two entries short, and that was charging customers for the wrong delivery

**Found while doing Phase 3**, and it is the first change in this branch a merchant will see as a fix rather than as a removal.

`AbstractConsignment::DELIVERY_TYPES_NAMES_IDS_MAP` at beta.15 names five delivery types — morning, standard, evening, pickup, express — and stops there. `PACKAGE_TYPES_NAMES_IDS_MAP` names five package types and omits pallet and envelope. The Phase 2 facades name all seven of each, because naming a type is what lets it be rejected legibly instead of replaced silently (DR-12, TR-000005).

**What that cost.** `AbstractDeliveryOptionsAdapter::getDeliveryTypeId()` read the short map, so it answered null for `same_day` and `early_morning`. `TrackTraceHolder.php:184` reads `getDeliveryTypeId() ?? DeliveryType::STANDARD`. A customer who chose and paid for early-morning delivery got a standard shipment, with no error at any layer. This is the live bug written out in DR-12; Phase 3 is where it stops, because `DeliveryOptions` resolves both types through the module facades.

The package-type half is **inert today** — nothing called `getPackageTypeId()`. The correction still stands rather than being reverted to match: one facade as the single source is the whole point, and a second, shorter map is exactly what produced this.

**The legacy path was worse, not better.** The SDK's `DeliveryOptionsV2Adapter::normalizeDeliveryType()` does `array_flip(MAP)[$id]` behind a `string` return type, so an old order carrying an id the map lacks was a fatal rather than a substitution. `DeliveryOptions::fromLegacyCheckoutData()` uses `DeliveryType::nameFromIdOrNull()` and answers null, which the caller can then handle.

`DeliveryOptionsEquivalenceTest` asserts both halves head-on — the SDK returning null where the module returns the id — so this is a pinned decision rather than a drift, the same treatment DR-9 got.


### DR-14: Receipt code was never restricted to standard delivery, because the gate read a string as an array

**Found while renaming `ChosenShipmentOptions`**, in the class the rename put under a microscope.

`Helper\ShipmentOptions::hasReceiptCode()` meant to allow receipt code only for PostNL, NL and standard delivery:

```php
$deliveryOptions = $this->order->getData(Config::FIELD_DELIVERY_OPTIONS) ?? [];
$deliveryType    = $deliveryOptions['deliveryType'] ?? DeliveryType::DEFAULT;
// ... || DeliveryType::STANDARD !== $deliveryType
```

`getData()` returns the JSON **string**. `??` uses `isset` semantics, and `isset($string['deliveryType'])` is false for a non-numeric offset — no warning, no error, no log line. So `$deliveryType` was always `DeliveryType::DEFAULT`, which is `STANDARD`, and the third check compared `2 !== 2`. Verified on PHP 8.2.28. The `?? []` branch reaches the same value.

**What that cost.** The delivery-type restriction did not exist. A PostNL NL **evening or morning** order got receipt code whenever a merchant had it on as a carrier default or ticked it on the New Shipment form. The SDK covers pickup only — `PostNLConsignment::getAllowedShipmentOptionsForPickup()` omits `RECEIPT_CODE` — and `canHaveShipmentOption()` does not distinguish evening from standard. It compounds: `getAllowedShipmentOptions()` returns only insurance and receipt code once receipt code is set, so the wrongly-enabled option also silently dropped signature, only recipient, age check, return and priority delivery, and made insurance mandatory. On the fulfilment path there is no capability guard at all.

**The obvious fix inverts the bug.** The stored value is the delivery type *name*, so adding a `json_decode` alone makes the comparison `2 !== 'standard'` and receipt code applies to nobody. Both halves had to change together.

Phase 3 fixes it and then removes the class of bug: `ShipmentOptionsResolver` takes the parsed `DeliveryOptions` and asks `getDeliveryType()`, so the comparison is name-to-name by construction and nothing re-parses that column. The same class had been reading the same field two different ways — indexed in `hasReceiptCode()`, correctly decoded in `getLabelDescription()` — which is what kept it invisible.

**What a merchant sees.** PostNL NL evening and morning orders stop getting receipt code, and keep signature and only recipient instead. An order with no stored delivery options still counts as standard, so the admin New Shipment form is unaffected. `Tests/Unit/Service/ShipmentOptionsResolverReceiptCodeTest.php` pins every delivery type separately; it fails on the old code for all six non-standard types.

### DR-15: Phase 4 is the first phase whose SDK surface differs between the two tags

**Assumed by the Strategy section:** that every phase before 9 can be written once and run at both
beta.15 and beta.31, because the v11 stack is byte-identical across them.

**True for Phases 1-3, false for capabilities.** Five differences, all verified by diffing the tags:

| Symbol | beta.15 | beta.31 |
|---|---|---|
| `ShipmentApi::postCapabilities()` | `($user_agent, $request)` | `($request, $user_agent = null)` |
| `postCapabilitiesContractDefinitions()` | same swap | same swap |
| `RefCapabilitiesResponseOptionsInsuranceOptionV2` | nested `insured_amount` only | adds flat `min` / `max` / `default` |
| `RefCapabilitiesResponseCapabilityV2::$physical_properties` | `...PhysicalProperties` | `...PhysicalPropertiesV2` |
| `RefCapabilitiesResponseOptionsOptionsV2` | carries `cooled_delivery`, `custom_label_text`, `delivery_date`, `return_contribution_fee` | dropped |
| `Support\EnumFallback` | absent | present |

Everything else the layer touches — `CapabilitiesRequest`, `CapabilitiesMapper`, `Configuration`,
`ObjectSerializer`, `ShipmentApiFactory` — is byte-identical, and the three v2 enums are identical
apart from a doc-version comment.

The argument swap is [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md)'s
defect 1 seen from the other side: beta.15's `HttpCapabilitiesClient` is *correct* for beta.15 and
becomes wrong at beta.25.

**Resolved by DR-16 rather than by a version shim.** Not calling `postCapabilities()` removes the
argument-order problem, and not binding to the response models removes the other four. An earlier
draft carried a `ReflectionMethod` check on the parameter order; it is unnecessary and was cut.

### DR-16: A generated response model is an allow-list, so the module reads the body

**Initially specified:** read `RefCapabilitiesResponseCapabilityV2` directly, which was already the
correction DR-2 made to using `CapabilitiesResponse`.

**Not far enough.** `ObjectSerializer::deserialize()` iterates `$instance::openAPITypes()` — the
model's own declared property list — and reads only those keys off the body
(`ObjectSerializer.php:533-549`). `RefCapabilitiesResponseOptionsOptionsV2` has no
`additionalProperties` catch-all. So every response key that SDK release does not declare is dropped
at parse time, silently.

That is precisely what [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md)
forbids: *unknown values pass through and are logged; iterate what the response contains; no code
path treats capability data as an allow-list.* And it is not a beta.15 problem — at beta.31 a newly
added API option is dropped until the SDK regenerates and we bump the pin. It is DR-2's defect one
layer down.

**Decision: the SDK builds the request, the module reads the response.** `CapabilitiesRequest` and
`CapabilitiesMapper::mapToCoreApi()` stay, per TR-000007 and DR-3; `Configuration` supplies the host
and auth format and `ObjectSerializer::sanitizeForSerialization()` the body, so the module hand-rolls
nothing. The decoded body is read into module-owned value objects under
`src/Model/Shipment/Capabilities/`.

The asymmetry is deliberate and matches DR-12. Outbound, strictness is what we want —
`sanitizeForSerialization()` throws on a value the API would reject. Inbound, the same strictness is
a filter, and a filter on capability data is the mechanism FR-000010 exists to prevent.

**Three things this removed** from the phase, each unnecessary rather than unimportant: the
`ShipmentApi` argument-order shim; a `ShipmentApiProvider` class, since only Phase 6b needs a
`ShipmentApi`; and the Guzzle middleware TR-000007 wanted for the `Accept` header, which was never
reachable anyway — `ShipmentApiFactory::make()` builds its own Guzzle client and
`ShipmentApi::$client` is `protected`. One header on the outgoing request replaces it.

### DR-17: A new Magento cache type is disabled until `env.php` says so

**Found while verifying Phase 4a on a real install.** `etc/cache.xml` declares the type, `cache:status`
lists it, `cache:clean myparcelnl_capabilities` flushes it — and it caches nothing.

`App\Cache\State::isEnabled()` reads `cache_types` from `app/etc/env.php` and returns `false` for an
absent key, and **`setup:upgrade` does not add a newly declared type** — tested by removing the key
and re-running it. So every upgraded install would make an uncached capabilities call on every
checkout and every admin form render, which is the exact regression TR-000007 exists to prevent, and
`cache:status` would report the type as present the whole time.

**Decision: enable it once from `UpgradeData`, and only when the key is absent.**
`Setup\Migrations\EnableCapabilitiesCache` reads `DeploymentConfig` rather than
`Cache\Manager::getStatus()`, because `getStatus()` lists every type declared in `cache.xml` and so
cannot tell an absent one from a disabled one. A type an admin has switched off stays off — that
switch is what it is for. A failed write, as on a read-only `app/etc`, logs the `cache:enable`
command instead of failing the upgrade.

### DR-18: A package-type-agnostic capabilities response is a superset, not a matrix

**Assumed in Phase 4a, and it shaped the whole layer:** that a request carrying only a country
returns one result per (carrier, package type), so a single call could answer an entire form.
Recorded in this plan as "one call serves a page".

**Wrong, and it shipped.** `CapabilitiesPostCapabilitiesRequestV2::packageType` is **singular**, and
the endpoint is *List shipment capabilities* — it answers for the shipment shape it is given. Ask
without a package type and the API groups every package type of a carrier into one result carrying
the **union** of their options. A live account returned exactly that:

```
POSTNL  packageTypes  PACKAGE,MAILBOX,UNFRANKED,DIGITAL_STAMP,SMALL_PACKAGE
        options       requiresAgeVerification,insurance,oversizedPackage,recipientOnlyDelivery,
                      priorityDelivery,requiresReceiptCode,returnOnFirstFailedDelivery,requiresSignature
        collo max     20
```

`oversizedPackage` on a digital stamp and a collo maximum of 20 on a mailbox are both nonsense, which
is what gives the superset away. `CapabilitySet::matching()` reads "mailbox is among this result's
package types" as "these options apply to mailbox", so the admin form offered every package option
on a mailbox. **Found by a reviewer on the rendered form, not by the tests** — every 4b test asserted
the mapping from a response to behaviour, and the fixtures encoded the same wrong assumption as the
code.

**Decision: two tiers of question, one per shape.** The broad call is still the only one that can
*enumerate* — which carriers, and which package types each has — because a narrowed response only
ever names the package type it was asked about. Everything that varies per package type (options,
insurance, the collo maximum) is asked with `packageType` set. Each shape is its own cache entry, so
this costs cold calls rather than warm ones.

**What it costs.** A cold admin form is one broad call plus one per distinct package type it offers,
so at most eight rather than one; a warm one is still zero. That keeps
[FR-000008](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md)'s
"not one uncached call per carrier on every page load" — the calls are per package type, not per
carrier, and only on a cold cache. Checkout is unaffected: it resolves a single package type before
it asks anything, so 4c needs one narrow call.

**A package type with no v2 name gets the permissive set, not the broad one.** No request can be
built for it, and answering from the superset would claim options nobody asked about.

**The lesson worth keeping.** 4a's classes needed no change at all — `Client`, `Repository` and
`CapabilitySet` were all correct. The bug was entirely in *what question was asked*, which no unit
test built on the same assumption could have caught. Where a response's grouping carries meaning,
verify it against a real account before building on it.

### DR-19: The admin insurance bound is advisory; the per-shipment bound is authoritative

**Found while planning Phase 5**, by reading the two endpoints at the pinned tag rather than trusting
the requirement's wording.

`POST /shipments/capabilities/contract-definitions` takes `{carrier}` and nothing else, and its
response carries **no country and no zone**. The settings screen has four zone fields per carrier
(`local`, `BE`, `EU`, `ROW`), so contract definitions cannot supply what FR-000009 criterion 2 asked
for literally.

**Asking shipment capabilities with a representative country per zone was considered and rejected.**
Bounds may differ between FR, DE and DK, so no single country can stand for "EU", and a zone-shaped
setting cannot hold a per-country answer whichever country is picked.

**What the setting is decides it.** `insurance_local_amount` is labelled *"Insure orders up to"* — a
merchant **cap**, not the contract maximum; `DefaultOptions` computes `min(order value × percentage,
cap)`. So there are two questions, and they get two sources:

| | Question | Source | Authority |
|---|---|---|---|
| Settings screen | Is this cap plausible for this carrier? | Contract definitions, one call per carrier | **Advisory** — states the range, refuses an out-of-range entry |
| Per shipment | What may *this* parcel be insured for? | Shipment capabilities for the order's real country **and** package type (DR-18) | **Authoritative** — clamps, logs |

The four zone fields are unchanged. The design holds even if the contract range is wider than any
single country's: an over-wide cap is clamped down at resolution and logged, which is FR-000009
criterion 4 and US-000010 Scenario 4 satisfied by construction rather than by a second bound.
FR-000009 criterion 2 is amended there — the admin screen validates per carrier, and per-destination
precision lands where the destination is known.

### DR-20: A temporary tier-snap shim keeps the branch exportable between Phases 5 and 6

`AbstractConsignment::setInsurance()` throws `BadMethodCallException` when a domestic amount is not in
the carrier's tier list, and `TrackTraceHolder` still calls it on the pinned SDK. So the moment
Phase 5's clamp can resolve a non-tier amount, domestic export breaks — and stays broken until
Phase 6 moves export off consignments.

**Decision: snap to the frozen tiers at that one call site**, marked `@todo Phase 6`, deleted with the
consignment path. It keeps the plan's standing rule that every intermediate commit stays runnable, and
it reuses the frozen constants `UpgradeData` needs regardless.

**This is a silent substitution, which DR-12 forbids, and it is recorded rather than hidden.** During
Phases 5–8 the branch ships €250 for a configured €137. It is one call site on an unreleased branch,
and the alternative is a knowingly broken export path across four commits. FR-000009 criterion 3 and
US-000010 Scenario 1 are therefore verified in Phase 6, not Phase 5 — stated in both documents rather
than left to be discovered.

### DR-21: The v11 shipment stack is not identical across the two tags either

**Assumed by the investigation table and by every phase before 6:** that the v11 stack is
byte-identical at beta.15 and beta.31, so Phase 6 can be written once and run at both.

**True for `Shipment.php` and `ShipmentCollection.php` only.** `git diff v11.0.0-beta.15
v11.0.0-beta.31` over the stack shows **42 changed files**, four of which Phase 6 writes through:

| Symbol | beta.15 | beta.31 |
|---|---|---|
| `Model\Shipment\ShipmentOptions` | 74 lines | +113: adds a `setDeliveryType` override, `getDeliveryType`, `getInsurance`, `toArray` |
| `RefShipmentShipmentOptions::setDeliveryType` | **enum-throws** outside `1..8`, strict `int` | throw removed |
| `Mapping\DeliveryTypeApiMapping` | absent | present |
| `RefShipmentCustomsDeclarationItem::setCountry` | enum-throws | throw removed |

This is DR-15 one layer down, and it is the same shape: beta.31 only **loosened**. So it does not
force the pin to move. Code written to beta.15's stricter surface runs unchanged at both — integer
delivery types in `1..8`, literal `0`/`1` for boolean options, label descriptions of 45 characters
or fewer.

**One case cannot be verified at beta.15.** DR-12's carry-through, where an unresolved *id* reaches
the API so the API can answer for it, throws locally at beta.15. TR-000005 already requires that
test to run at beta.31. Both tags still end in a **named error rather than a substitution**, so
DR-12's rule holds at both; only the mechanism differs.

### DR-22: The two customs stacks are unrelated, so Phase 6 writes a second builder

**Initially read** `src/Helper/CustomsDeclarationFromOrder.php` as the reuse target for
`setCustomsDeclaration()`, since it already builds a declaration and already iterates items once.

**Wrong, because they are different APIs.** That helper builds the legacy `Model\CustomsDeclaration`
from `MyParcelCustomsItem`, which TR-000005 records as `@internal Legacy — used by Order v1
(fulfilment)`. The v11 shipment path needs `RefShipmentCustomsDeclaration` and
`RefShipmentCustomsDeclarationItem`. Two builders is correct while the fulfilment path stays on
Order v1; revisit only if Phase 8 moves it.

Three behaviours differ and must be carried deliberately rather than discovered:

- `RefShipmentCustomsDeclaration::setItems()` **replaces the whole array** — there is no `addItem()` —
  and throws below 1 or above 100 items.
- `RefShipmentCustomsDeclarationItem::setDescription()` **rejects** over 50 characters where
  `MyParcelCustomsItem` silently truncated with `Str::limit`. Truncate before setting, or a long
  product name becomes an exception instead of a short label.
- `setAmount()` throws below 1 and above 99999.

### DR-23: Phases 6 and 7 merge, because a Shipment has nowhere to go until the collection is replaced

**Assumed by the phase split:** that Phase 6 could swap the built object and leave orchestration to
Phase 7.

**It cannot.** The only two doors into the export path are `MyParcelCollection::addConsignment()` and
`addMultiCollo()`, and both take `AbstractConsignment`. A `Shipment` cannot be passed to either, so a
Phase 6 that ends at the builder leaves the branch unexportable — breaking the standing rule that
every intermediate commit runs.

**A bridge was considered and rejected.** A throwaway `Shipment`→`AbstractConsignment` adapter would
keep the seam and the one-commit-per-phase rule, but the consignment path would still export, so the
DR-20 insurance shim would have to survive Phase 6 — and FR-000009 criterion 3 and US-000010
Scenario 1 would slip again, having already been moved once. The bridge would also sit on exactly the
path DR-12 is about.

**Merged instead**, split into 6a/6b/6c along its own seams. The consignment path then dies whole,
which is what removes the shim and makes the insurance range verifiable where it was promised.

### DR-24: Nothing ever decided to drop the fallback's date and pickup location

**Recorded in Phase 3 and again while planning Phase 6** as a behaviour to preserve, on the grounds
that supplying a date would change what gets exported. Then reversed, because the premise was wrong.

`DeliveryOptionsFromOrderAdapter` assigned both from `$originAdapter`:

```php
$this->date            = $originAdapter ?? null;
$this->pickupLocation  = $originAdapter ?? null;
```

**`$originAdapter` was never a constructor parameter, in any commit.** It is undefined in the file's
first version (`bdef21c`, 2020-03-24) and in all four that followed, so both expressions always
evaluated to null and `??` suppressed the notice for six years. `git log -S'originAdapter'` returns
only the commit that added the file, one unrelated `wip`, and Phase 3's deletion. There is no commit
where it was live and no discussion to find — it is a copy-paste artefact, not a decision.

So this is not a behaviour change weighed against FR-000006's behaviour-preserving remit; it is a
defect fix, and the third correction of its kind on this branch after DR-13 and DR-14. `date`,
`pickupLocation` and `packageType` are read from the stored data, the way every sibling constructor
already reads them.

**A real stored pickup order** carries `deliveryType`, so it routes to `fromCheckoutData()` and was
never affected. What the fix reaches is the genuine degrade path — and the PPS path, which also
catches `InvalidArgumentException` and so falls back for a pickup whose location failed to parse.

**The trap this exposed is the more valuable half.** `fromOrderFallback()` is handed **raw** stored
data by both call sites, while `fromCheckoutData()` is only ever reached through the factory, which
snake-cased the nested `shipmentOptions` and `pickupLocation` keys first. The widget writes them in
camelCase and a `toArray()` round trip writes them back in snake_case, so both spellings are in the
database — and reading raw camelCase through `PickupLocation::fromCheckoutData()`, whose fields all
default to `''`, produces a **fully-empty** pickup location rather than a null one. Worse than the
bug being fixed.

So the invariant "data reaching a named constructor is normalised" was held by the *caller*, and
nothing enforced it. That is the actual defect; the missing pickup location is one instance of it.
The normaliser is now `private` on `DeliveryOptions` and **every named constructor calls it on its
own input** — it is idempotent, so a second pass costs nothing — and the factory no longer
normalises at all. `DeliveryOptionsFactory` is reduced to what its name says: it detects which
stored shape applies, dispatching on top-level keys, which are camelCase in every shape.

Checked before writing a second implementation: the SDK's `Support\Str` has only per-string `snake()`
and `camel()`, and no array-key normaliser exists anywhere in the module or the SDK.

### DR-25: A pickup that cannot be read is refused, not degraded

**Left open by the Phase 6b notes**, on the grounds that a pickup whose location fails to parse is
arguably worth refusing but that nothing had decided.

**Decided: refuse that shipment.** `fromOrderFallback()` would ship it as a home delivery, which is
a different delivery from the one the customer chose and paid for — the substitution DR-12 and
FR-000010 exist to prevent. `ShipmentBuilder::parse()` therefore lets `InvalidArgumentException`
through as a per-shipment failure naming the order, and catches only `BadMethodCallException` for
the genuine unknown-shape fallback. One order fails; the rest of the batch exports.

The PPS path is untouched and still degrades, because Phase 8 owns it.

### DR-26: The reference identifier gains a collo suffix

TR-000006 names the Magento **shipment** entity id. That is unique per shipment but not per label: a
`label_amount` above one makes several Magento tracks for one shipment, `create()` answers
`[shipmentId => referenceIdentifier]`, and a shared reference pairs only one of them. Correlating by
position is what TR-000006 forbids in the first place.

**So the reference identifier is `<shipment entity id>-<collo number>`, always suffixed** rather than
suffixed only from the second collo, so there is one format to read and one prefix to match on —
which is what `ShipmentCollection::whereReferenceIdentifierPrefix()` is for. The API attaches no
meaning to the value and the module stores the shipment id rather than the reference, so nothing
local depends on the old format.

### DR-27: DR-7 was only half fixed by Phase 3, and the seed was the other half

**Assumed by the Phase 6b plan:** that routing the builder through `ShipmentOptionsResolver::hasAgeCheck()`
fixes the unreachable age-check tiers "for free", because the resolver passes `$order->getItems()`
where `TrackTraceHolder` passed the `Track` itself.

**It fixes the loop, not the seed.** `getAgeCheckFromProduct()` started at `$hasAgeCheck = false` and
only assigned `null` *inside* the loop, so an order with **no items** returned `false` — an opinion —
and `??` never fell through to the carrier default. The tier was still unreachable for exactly the
orders most likely to hit it.

Found by the test that was written to prove the fix, which failed. The seed is now `null`, and an
explicit non-`1` product value assigns `false`, which keeps a product-level opt-out beating the
carrier default. The `->todo()` on that case is gone.

### DR-28: `RefShipmentCustomsDeclarationItem::setCountry()` cannot be called at all

The generated enum for that field lists exactly one allowable value, `''`, at **both** beta.15 and
beta.31, so `setCountry('NL')` throws `Invalid value 'NL' for 'country', must be one of ''`. An SDK
generation defect, not a rule.

**The country goes in through the constructor**, which uses `setIfExists` and bypasses every setter,
and the field is declared `string` so `ObjectSerializer`'s enum gate never runs on it either. The
value serializes correctly. The cost is that `listInvalidProperties()` reports a false invalid
country for every item, which is why `ShipmentValidator` does not walk the customs declaration —
customs is validated by construction instead.

### DR-29: `Shipment::valid()` is not a replacement for `$consignment->validate()`

Phase 6b's plan said to "call `valid()` explicitly". That is necessary and nowhere near sufficient:
`Shipment::listInvalidProperties()` checks only that carrier and options are non-null plus a few of
its own enum fields, and **does not recurse** into recipient, options, physical properties or pickup.
A missing `recipient.cc` or `physical_properties.weight` would have passed it and failed at the API
as a batch-level error, replacing the per-order message the admin gets today.

Measured, not assumed: a `Shipment` with **no country and no weight** answers `valid() === true`,
serializes cleanly and reaches the API. The SDK's own `listInvalidProperties()` on the nested
recipient and physical properties names both faults at once.

`Model\Shipment\ShipmentValidator` walks those nested models. It carries **no MyParcel rules of its
own** — it calls the SDK's generated validators recursively, which is the only reason it is not the
duplicated local judgement FR-000010 warns about.

**It is provisional, and one acceptance finding decides its fate.** `create()` takes a whole chunk,
so the blast radius of an unvalidated bad shipment depends entirely on how the Core API answers a
partial rejection:

| API behaviour on one bad shipment in a chunk | Cost without the validator |
|---|---|
| Atomic 4xx for the whole request | The whole chunk fails — **20 orders by default**, where `$consignment->validate()` cost exactly 1 |
| Ids returned for the good shipments only | **1 order.** `ShipmentExportService::recordCreated()` already fails anything the response did not name, so the per-order behaviour needs no local check at all |

**Observed on acceptance: it rejects atomically** (DR-31). The validator therefore stays, and chunk
size doubles as failure granularity. If the API reports per shipment, delete `ShipmentValidator` and rely on
`recordCreated()`. If it rejects atomically, the validator stays and chunk size doubles as failure
granularity — worth stating in the setting's tooltip if so.

The alternative considered and not taken: drop the validator and isolate a failed chunk by re-sending
it one shipment at a time. It reaches a blast radius of 1 with no local rules, but is safe only for a
4xx. Retrying after a timeout would create duplicate billable shipments, because the API deduplicates
nothing (TR-000006).

### DR-30: `ShipmentCreateService::create()` cannot succeed at beta.15, so 6b needs the pin moved

**Found on acceptance**, not by reading: a successful export answered
`Unexpected response type returned by ShipmentApi::postShipments()` for every order.

| | beta.15 | beta.31 |
|---|---|---|
| Generated client returns on 200 | `ShipmentResponsesPostShipmentsV12` | `ShipmentResponsesPostShipmentsV12` |
| `parseCreateResponse()` requires | `InlineObject` | `ShipmentResponsesPostShipmentsV12` |

The service rejects the only type its own client produces. **Fixed at beta.31**, so this is the first
place the plan's founding assumption — *the whole module can be migrated against the installed SDK,
bumping the pin last* — does not hold. Every earlier phase held because beta.31 only **loosened**;
here beta.15 is simply **broken**.

There is no clean route around it inside the module. Calling `postShipments()` directly works at
beta.15 and breaks at beta.31, where `$user_agent` moves from the **first** argument to the
**sixth**. So the pin moves for 6b rather than at Phase 9.

**The failure mode is the dangerous part and is worth remembering.** The API returned 2xx and created
the shipments; the SDK threw while *parsing*, the module recorded the orders as failed, and no
shipment id was stored. A re-run would have created second billable shipments. TR-000006 point 4
anticipated duplicate exports from a re-run, but not from a **local exception after a successful
create** — the record is what makes a re-run safe, and an exception between the API's yes and that
record is the one gap in it.

### DR-31: The only per-order pointer in a rejection is a key the generated model throws away

The API refuses a batch **atomically** — three orders, one bad postal code, one `POST`, one 422 with
one `request_id` — which settles DR-29's open question. Every order in the chunk therefore received
the same message, prefixed with its own increment id, which reads as a per-order diagnosis of a
per-chunk event.

**What the spec says, and what the API actually sends, are different — the spec is not the one to
build against.** DR-31 was first written from the spec below; acceptance then produced the real
thing, and it is **RFC 9457 Problem Details**:

```json
{"type":"urn:problem:invalid-shipments","title":"Invalid shipments","status":422,
 "detail":"Verzending validatiefout","instance":"/shipments","request_id":"…",
 "errors":[{"type":"urn:problem:invalid-postal-code","title":"Invalid postal code",
            "detail":"postal_code 'OPA' doesn't look like a correct postal code for country NL",
            "instance":"/data/shipments/0/recipient/postal_code"}]}
```

Three differences, each of which cost something:

| | Spec (`common_responses_user_error`) | Actual |
|---|---|---|
| Summary | `message` | `detail` |
| Per-error text | `message` | `detail` — **the generated model declares neither this nor `instance`** |
| Which shipment | keys of an `errors` **object** | `instance`, a JSON Pointer, inside a plain **list** |

So `errors` arrived as a list, the keyed-object path never fired, and nothing was attributed: all
three orders were told *"MyParcel refused this batch of 3 orders: Invalid postal code Invalid
recipient phone number"* — two bare `title`s concatenated, with the sentence naming the offending
value (`detail`) dropped entirely. The pointer that identifies the shipment was sitting in a field
neither the spec nor the model mentions.

The parser now reads `instance` for the index and `detail` for the text, keeps the documented shape
as a fallback since a future regeneration will follow it, and trims the pointer to the part an admin
can act on (`recipient.postal_code`). **Reasons also accumulate per order** rather than overwriting:
one shipment broke two rules here, and showing only the first would have cost a second fix-and-retry.

The original spec text, kept because it is what a reader will find if they go looking:

`common_responses_user_error` in the Core API spec types `errors` as:

```yaml
errors:
  type: ["array", "object"]
  items:                { $ref: common_error_user }
  additionalProperties: { $ref: common_error_user }
```

In the **object** form the keys are the only thing pointing at a shipment, and the generated
`CommonResponsesUserError` types `errors` as a plain `CommonErrorUser[]` — so deserializing discards
them. This is DR-16 one layer down, on the error path: **read the raw body.** Guzzle's own exception
message is truncated at ~120 characters and carries none of it either.

`ShipmentExportService::attributeFailure()` therefore reads `ApiException::getResponseBody()`, and:

- a keyed error naming a shipment index blames **only that order**, keeping the API's field pointer
  in the message so the admin knows what to correct;
- the rest of the chunk is told it did not ship *because another order was refused*, rather than
  being accused of the fault;
- an error that points at nothing is recorded against every order in the chunk, worded as a batch
  rejection;
- the raw body is logged whole, because the key format is **not in the spec** and this is what makes
  an unrecognised one diagnosable rather than silently unattributed.

**A list is not a map.** `errors` in its array form has keys that are its own positions, not shipment
indexes; only the keyed form points at a shipment. Conflating them blamed the first order in the
chunk for every unkeyed error.

**Isolating a failed chunk by re-sending it one at a time was considered and rejected** — it costs a
round of calls and the merchant's workflow is to fix the named order and re-run the same batch, which
is safe precisely because an atomic rejection creates nothing.

### DR-32: The pin moved at 6b, and Phase 9's parity tests went with it

DR-30 forced the bump. Doing it revealed what the plan had bundled into Phase 9, and most of it is a
consequence of the pin rather than separate work — so it landed here.

**Deleted, because their subject no longer exists.** They existed to prove parity *while beta.15 was
installed*, which is a job that cannot be repeated once it is not:

| Removed | Why |
|---|---|
| `Tests/Unit/Model/Shipment/ConstantEquivalenceTest.php` | asserted each module constant equals the beta.15 SDK value |
| `Tests/Unit/Adapter/DeliveryOptions/DeliveryOptionsEquivalenceTest.php` | compared the module value objects against the removed SDK adapters |
| `TrackTraceUrlTest`'s *matches the SDK helper it replaces* | `Sdk\Helper\TrackTraceUrl` is gone; the three module-behaviour cases stay |
| `LegacyInsuranceTiersTest`'s beta.15 equivalence case | called `getInsurancePossibilities()` on the removed consignments; the `snap()` cases stay |

**`LegacyInsuranceTiers::acceptableForSdk()` and `zoneFor()` are deleted as dead code.** Both existed
only for the DR-20 shim, which 6b removed. `UpgradeData` uses `snap()` and `forCarrierAndZone()`
only, so those stay — the class survives, halved.

**Three behaviour changes at beta.31 that the plan had not recorded**, all found by the suite:

1. **`InvalidConsignmentException` is deleted** along with the consignment stack, and
   `Helper\SplitStreet` now throws a plain `\InvalidArgumentException`. It also no longer depends on
   `AbstractConsignment` at all — it reads `Services\CountryCodes` and its own `MAX_STREET_LENGTH`,
   which is why it survives the removal that would otherwise have broken it. Only the expected class
   changed; nothing swallows the rejection either way.
2. **`fresh_food` and `frozen` became sendable.** TR-000005 records them as read-only — present in a
   capabilities response but with no setter on `CapabilitiesOptionsV2`, so a request could not ask.
   beta.31 adds `setFreshFood()` and `setFrozen()`. **No module option is unsendable any more**,
   verified by mapping every entry of `ShipmentOption::V2_KEYS_MAP` through the SDK's own mapper.
   `Capabilities\Client`'s drop-logging stays — FR-000010 still forbids a silent drop — but its
   subject is now an unknown option rather than a known-but-unsendable one, and its test says so.
3. **`postShipments()` takes the request first and `$user_agent` sixth**, where beta.15 took the user
   agent first. Production code never calls it directly, so only the test spy was affected — and it
   failed *silently*, recording nothing rather than erroring. The spy now finds the request by type
   wherever it sits, so the next reordering cannot quietly blind it.

**beta.33 was tried and reverted.** The suite is green on it and nothing in the shipment path
changed, but the generated error models are byte-identical, so it does not close the DR-31 gap and
buys this PR nothing. Recorded in TR-000005's assumptions; bump it as ordinary maintenance after this
merges.

**The create response shape changed too**, which the plan had not flagged: beta.15's parser read
`data.ids[]`, beta.31's reads `data.shipments[]` as full `ShipmentDefsShipment` objects. The
`[shipmentId => referenceIdentifier]` return contract is identical, so `ShipmentExportService` needed
no change — only the test fixture did.

### DR-33: A rejected chunk is re-sent once without the orders the API named

**Found by using it.** A batch with one bad postal code produced one useful message and nineteen
repeating it, each prefixed with its own increment id so every order read as individually at fault.
The admin then had to re-find the orders, because Magento clears the grid selection on the way back.

The rejection already names which shipment it objected to (DR-31), and **the API reports every faulty
shipment in one response** — confirmed on acceptance. So the fix is to use that: exclude the blamed
orders and re-send the chunk **once**. A 50-order batch with one bad order now ships 49 and reports
one, which removes the message noise and the re-selection problem together.

One retry, not a loop: after excluding everything the first response named, nothing faulty is left. A
second rejection is not expected and is reported rather than retried again.

**Retrying is only safe because a 422 created nothing.** A timeout or a 5xx says nothing about whether
the request was processed, and the API deduplicates nothing (TR-000006), so re-sending could bill the
merchant twice. `isSafeToRetry()` gates on 422 alone; widening it needs that argument made for the
status being added.

**Failures are now two kinds.** *Blamed* keeps its own message with the API's sentence and field.
*Collateral* — an order that merely shared a rejected chunk — is counted in one line and never named,
because a per-order line for each is the noise this removes. Only reachable when a retry also fails.

**Rejected: a checkbox restore and a grid status filter.** The retry removes the need for both. The
restore was also awkward at the time, because `downloadPdfOfLabels()` ended in `exit` and there was no
return trip to hook — DR-35 has since removed that, but the retry still makes the restore unnecessary.
Filtering on `track_status` would need a stable value first: it stores a *translated* display string.

### DR-34: One prefix, one owner

`ShipmentBuilder` prefixed `Order %s: ` on its own exceptions while both catch sites prefixed the
increment id again, so a local validation failure rendered as
`000000116: Order 000000116: recipient: invalid value for 'postal_code'…`.

**The reporting layer owns the prefix**, since it already keys by increment id. The builder says only
what went wrong; `MagentoCollection::setNewMyParcelTracks()` and `Observer\NewShipment` name the
order. That also removed an `$incrementId` argument threaded through five private methods that used it
for nothing else.

Build failures are now recorded in the report as well as shown, so `getLastReport()` is a complete
account of a run rather than only of what the API said. They are still shown where they happen rather
than deferred, because `setNewMyParcelTracks()` has callers that never reach `createMyParcelConcepts()`
— the return-label action and the status cron.

### DR-35: The export redirects and a second request fetches the labels

**The grid showed stale barcodes and no messages after an export**, and the cause was one line:
`MagentoCollection::downloadPdfOfLabels()` ended in `exit`, so the controller's redirect never ran and
the page was never re-rendered. In *open in new tab* mode the grid tab was not even navigated.

**Nothing was stale but the page.** `updateMagentoTrack()` runs *before* the download, so barcodes and
`track_status` were already written. It also explains an asymmetry that made this hard to see: a
wholly failed export produces no PDF, so no `exit`, so the redirect runs and its messages *do* appear.
Only a partly successful export went quiet — which the DR-33 retry made the common case.

A response can be a PDF or a page, not both. So the export now ends at `sendTrackEmails()` and falls
through to the redirect that was always there, and the labels are collected by a second request:

- `Service\Export\PendingLabels` carries the exported **order ids** — never API keys, which the print
  controller re-resolves per order from store config — through `Magento\Backend\Model\Session`.
  Reading **takes**: the page that receives them owns them, so a reload does not download the same PDF
  twice and an admin who navigates away is not ambushed on a later visit.
- `Controller\Adminhtml\Order\PrintMyParcelLabels` groups those orders' shipment ids by resolved key
  and calls the existing `ShipmentExportService::fetchLabelPdf()`. It creates nothing.
- `view/adminhtml/web/js/label-download.js` starts the download from the reloaded grid. Setting
  `window.location` to an `attachment` response downloads *without* navigating, so the fresh grid
  stays on screen; the `inline` case opens a tab, because inline in this tab would replace the grid.

**`setPdfOfLabels()`, `downloadPdfOfLabels()` and `$labelPdf` are deleted** from `MagentoCollection`,
along with the `exit` and the raw `header()`/`echo`. The print controller returns a
`ResultFactory::TYPE_RAW` result instead — `FileFactory` was rejected because it always sends
`attachment` and the *open in new tab* option needs `inline`.

**It fixes both entry points**, because the change is server-side plus a hook on the grid page rather
than in the submit path: the module's own JS modal and the native ui_component mass action
(`sales_order_grid.xml:4-13`) both benefit.

~~`Block\Sales\OffersPendingLabels` is a trait because both grid blocks need it and neither is the
other's parent.~~ **Wrong, and DR-36 undoes it:** `ShipmentsAction` was simply the one block that had
never joined the `OrdersAction` hierarchy the other three were already in.

**DR-41 supersedes the redirect.** The two-request split survives, but the export stops navigating, so
`PendingLabels` and the session hand-off it needed are gone.

### DR-36: `ShipmentsAction` rejoins the hierarchy, and the row action stops exporting to print

Two loose ends from DR-35, both smaller than they looked.

**The duplicated block was one class, not a shared concern.** `OrdersAction` and `ShipmentsAction`
had identical `getOrderAjaxUrl()`, `getShipmentAjaxUrl()`, `getAjaxUrlSendReturnMail()` and
`getPrintSettings()`, identical `$config`, identical constructors — while `OrderAction` and
`ShipmentAction` **already extended `OrdersAction`** for exactly that reason. So DR-35's
`OffersPendingLabels` trait was solving a problem that did not exist; `ShipmentsAction` now extends
`OrdersAction` and is an empty class, and `getPendingLabelsConfig()` sits on the parent where all
three subclasses inherit it.

Two accidents went with it: `getPrintSettings()` gains the `: string` the shipment copy had, and
`$this->config = $this->Config = …` loses its second assignment — a dynamic property written and
never read, surviving only because `DataObject` carries `#[\AllowDynamicProperties]`.

**Printing no longer means exporting.** *Download label*, offered only on already-exported orders,
pointed at `CreateAndPrintMyParcelTrack` with `mypa_package_type=1`: the whole export chain, every
step skipped by `withoutAlreadyShipped()`, purely to reach the PDF — and forcing package type 1
whatever the order actually shipped as. It now points at `PrintMyParcelLabels`, which creates nothing.

**The mass action deliberately does not change.** *Print MyParcel labels directly* runs over whatever
is selected, including orders never exported, and those must be exported before a label exists. It
already reaches the print controller one hop later, through DR-35's redirect and session handoff.

**This is `PrintMyParcelLabels`' first user-facing caller.** It was previously reachable only as the
second leg of an export the same admin had just run, so its `order_ids` were always ones the module
had just written; now they come from a URL. `ADMIN_RESOURCE = 'Magento_Sales::shipment'` was already
on it and it returns labels only for orders carrying a MyParcel shipment id in this install — but that
gate starts doing real work here.

### DR-37: The HS code was stored as an integer

`myparcel_classification` is an `input => 'text'` attribute that inherited `'type' => 'int'` from
`UpgradeData::DEFAULT_ATTRIBUTES`, so every HS code was written to `catalog_product_entity_int`.

An HS code is a numeric string of up to 18 characters that may carry dots (`6109.10`). An INT column
holds none of that. Measured on the dev database before the fix, attribute 160: of ten rows, **one
was exactly 2147483647** — INT_MAX, so a longer code had been clamped — and eight were `0`, the old
default, indistinguishable from a real code. Leading zeros were the symptom that surfaced it; the
clamping was the worse half.

Every other free-text MyParcel attribute — `myparcel_age_check`, `myparcel_dropoff_delay`,
`myparcel_fit_in_mailbox` — already overrides to `varchar`. This one was the outlier.

**Fixed as `varchar`, defaulting to empty, capped at 18 in the form.** `Setup\Migrations\ClassificationToVarchar`
moves the surviving values across and drops the zeros, which were the old default rather than data.
Nothing recovers what the INT column destroyed, so it preserves what survived rather than pretending
to repair it.

**Character set is not validated in the browser, deliberately.** No stock Magento rule expresses
"digits and dots": `validate-digits` rejects the dot and `validate-number` both accepts negatives and
rejects two-dot forms such as `6109.10.00`. Only the length is enforced client-side; a wrong character
set is caught at export, where the API names the order.

**The 10-character truncation in `CustomsDeclarationBuilder` was ours, not the API's.** The Core API
spec types `classification` as a plain `string` with no `maxLength`, so that cap would have halved a
valid 18-character code. It is now 18, matching the attribute.

**One path still truncates and we cannot change it.** `Sdk\Model\MyParcelCustomsItem::setClassification()`
does `substr($classification, 0, 10)` inside the SDK, so a code longer than ten characters survives the
v11 shipment export but is cut on the Order v1 (PPS) export. Worth raising upstream; this module does
not patch the SDK.

**On when a migration actually runs.** Magento calls `UpgradeData::upgrade()` only while
`setup_version` exceeds the stored `data_version`, and `setup_version` is bumped automatically at
release by `private/updateVersion.js`. On an install sitting at the released version the two are
equal, so **no gate in that file fires until the next release**, and a gate numbered above the coming
version re-fires on each release until the module passes it. That is why this migration is idempotent
rather than merely tidy — verified by running it twice.

### DR-38: Reprinting an existing label is not "nothing to do"

**A regression from 6b, found by using it.** Selecting already-exported orders and choosing *Print
MyParcel labels* stopped reprinting them and warned *"No MyParcel shipments to process."*

Before 6b, `syncMagentoToMyparcel()` pulled existing consignments into the SDK collection and the
order controller's guard asked whether that collection was empty. An already-exported order put its
consignment in it, so the guard passed and the label fetch covered existing and new alike.

6b removed the collection, and with it that method's only job: status and barcode are read back by
stored id now, and the labels come from the same stored ids. It is **deleted** rather than left as a
no-op, along with its four call sites in the two export controllers, the return-mail controller and
the status cron. It was never on `MagentoCollectionInterface`, so nothing outside those four lines
knew about it.

What actually broke was the guard beside it, rewritten as `! $this->orderCollection->builtShipments` —
which holds only *newly built* shipments, so an all-already-exported selection returned before it
could hand the label ids on.

**The label fetch was never the problem.** `getMyparcelConsignmentIdsByApiKey()` reads every track in
the shipment collection, already-exported ones included; only the early return stopped us reaching it.

**The shipment grid was never affected**, because its controller guards on `VALUE_CONCEPT` alone — at
HEAD and now. That asymmetry was the tell, and the two now agree.

Two changes, and a third deliberately not made:

- the order controller returns early for `concept` only;
- `createMyParcelConcepts()` warns only when nothing was built **and** nothing in the selection
  carries a shipment id, so a reprint is no longer reported as a failure;
- **the duplicate guard stays.** `setNewMyParcelTracks()` and `withoutAlreadyShipped()` still skip a
  track that already has a shipment id. A reprint must remain a pure label fetch that creates nothing
  — that guard is what stopped a repeated mass action billing a second shipment, and it is the reason
  this looked like a bug rather than causing one.

### DR-39: The barcode is read after the label, not before it

A shipment is created as a concept — status 1, no barcode — and gets one when its **label is
requested**, which moves it to status 2. DR-35 put the label fetch in a second request, leaving the
only `updateMagentoTrack()` in the first one, before the barcode exists. The grid therefore filled in
on the *second* print, or whenever the status cron next ran.

Measured before the fix: orders exported once sat at `myparcel_status = 1` with `track_number = –`,
while orders printed more than once were at status 2 with real barcodes.

`PrintMyParcelLabels` now refreshes the printed orders once the PDF is in hand, reusing
`MagentoOrderCollection::updateMagentoTrack()` — which fetches the latest shipments, writes status and
barcode onto the tracks and updates the grid columns. The refresh is wrapped: the PDF already exists
at that point, and losing the download over a write-back would trade a small problem for a larger one.

**This is better than the behaviour it replaces, not a restoration of it.** beta.15's
`setPdfOfLabels()` (`MyParcelCollection:504-530`) performed no data refresh either, and
`addConsignmentByConsignmentIds()` only built id-stubs — so the old flow also missed the barcode on
the first run and picked it up on a later one. An earlier note in DR-35's wake claimed otherwise; it
was wrong.

### DR-40: `setLatestData()` is gone

Its only job was to populate `$latestShipments` ahead of a consumer, and both consumers already fell
back on their own — `$this->latestShipments ?: $this->exportService->fetchLatest(...)`. All three call
sites placed it immediately before a single consumer, so every chain issued exactly one API call with
or without it.

Removed along with `$latestShipments` and the two fallbacks, which now simply fetch. Same shape as
`syncMagentoToMyparcel()` before it: a seam that existed to memoise the SDK collection and outlived
it. Both are worth remembering as a pattern — a method that reads like orchestration but, once the
thing it orchestrated is gone, only hides where the work happens.

### DR-41: The export stops navigating

**The grid was fetched twice per export and the selection was lost.** DR-35 made the export redirect
so the grid would re-render with its barcodes and messages. That worked, but it bought the re-render
at the price of a page load, and the page load was the problem.

**The barcodes were a race, not a guarantee.** `Magento_Ui/js/grid/provider::initialize()` calls
`clearData()` and then `resolver(this.reload, this)` (`provider.js:47-59`): rows start empty and
arrive from **one** AJAX call, which ran alongside the label request that mints the barcodes. It
usually won — which is why barcodes appeared and the bug stayed hidden — but nothing ordered the two.

Three more faults had the same single cause, `window.location.href` in `_createConsignment`:

- the ticked checkboxes were discarded, so a batch where one address was wrong could not be corrected
  and re-run — the complaint that started this thread, and the reason DR-33's per-order attribution
  was less useful than it looked;
- `window.open` ran outside a user gesture and was blocked, so *open in new tab* produced a download
  and two grid tabs;
- `PendingLabels` existed solely to carry order ids across the redirect.

**So the export no longer navigates.** `mass-action.js` fetches it, and both `CreateAndPrintMyParcelTrack`
controllers answer JSON built by `Service\Export\ExportResponse`: the messages that ran, and where to
fetch the labels. Then, and only then, `provider.reload({refresh: true})` — the export's *only* grid
fetch, after the barcodes exist rather than against them.

**The export has three entry points, and changing the response format breaks every one that
navigates.** Two were missed at first and shipped the raw JSON to the screen:

| Entry point | Was | Now |
|---|---|---|
| The module's modal (`_showMyParcelModal`) | `window.location.href` | `fetch` |
| *Print MyParcel labels directly* (`sales_order_grid.xml`) | `utils.submit()`, a form POST | `callback` → `exportSelected` |
| The seven row actions (`TrackActions.php`) | plain `href` links | `callback` → `exportRow` |

Both grid components resolve a `callback` the same way — `{provider, target}` reaches
`registry.async(provider)`, which calls `component[target](…)`. The provider does **not** have to be a
uiComponent: `registry.get()` returns anything `registry.set()` put there, so `mass-action.js`
registers itself as `myparcel_grid_massaction` and both XML and PHP name it. The row actions pass
`(actionIndex, recordId, action)` and the mass action `(action, selections)`, which is the only
difference between the two handlers.

A row action with a `callback` object also stops being a link at all — `isHandlerRequired()`
(`columns/actions.js:204`) reads `_.isObject(action.callback)` — so the `href` stays as the URL to
fetch rather than to follow.

**A page with no grid reloads instead.** The single order and shipment views run the same export
through the same modal, and they used to end on the order grid because the controller redirected
there. They now stay put, so they reload themselves — unless something failed, since the reload would
take the message explaining it along too.

Two details decide whether the reload works at all, and both fail silently:

- **`{refresh: true}` is not optional.** `data-storage.js:103` reads
  `!options.refresh && cachedRequest ? <cached> : <new request>` — a bare `reload()` re-renders the
  same stale rows and looks exactly like a fix that did nothing.
- **`registry.get(name, callback)` queues** until the component registers (`registry.js:222-228`), so
  the lookup does not depend on script order. A *wrong* name therefore never errors; the callback
  simply never fires. The names come from the ui_component file names, since those `<listing>` roots
  carry no `name` attribute, and `ShipmentsAction` overrides `getGridDataSource()` for the second grid.

**Messages are harvested, not refactored.** `ExportResponse` reads
`messageManager->getMessages(true)`, which takes and clears the shared `Message\Session` pool
(`Manager.php:120-133`). All thirteen `addErrorMessage`/`addWarningMessage` call sites in the two
collections are untouched — including the ones on the separate Manager instance `MagentoCollection`
creates for itself, which write to that same pool.

**`loadingPopup` went with it.** It hides itself after five seconds (`theme.js:501-546`), which a
navigation concealed and an AJAX export would not: any batch over five seconds would have run with no
loader at all. Replaced by the `processStart`/`processStop` pair `label-download.js` already used.

---

## Standing decisions

- **Git:** one PR on `feat/use-sdk-v11-shipments`, **one commit per phase**. The composer bump is the last code commit.
- **Multi-account label PDF:** merge into a single PDF in Magento via `setasign/fpdi`, preserving today's UX, and make fpdi an explicit module dependency. Specified in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- **Carrier options:** migrate to contract definitions now, not deferred. The SDK warns this is not a 1:1 replacement for the `CarrierOptions` model; consumers read the generated contract-definition models directly.
- **Latent multi-store bugs in scope:** cron PPS status polling (`src/Cron/UpdateStatus.php:126` polls one key only) and return labels using the wrong key.
- **We do not touch the SDK.** No PR, no `vendor/` patch (per `CLAUDE.md`). Three defects are raised as issues; the module works around them.

---

## Prerequisite: separate PR · *Merged*

Landed as **#967, `feat: hash api key in path and cleanup scoped account settings`**, with **no requirement documents** — small, self-contained, rationale lives in its PR description. What it produced is below; the description of the work is kept because the reasoning still explains the shape of what shipped.

**Naming note:** the legacy path was `myparcelnl_magento_general/account_settings_{apiKey}`, not `account_data_`. The prefix is `Config::XML_PATH_ACCOUNT_SETTINGS`; a cleanup routine that does not match it runs clean and deletes nothing, which looks like success.

**1. Hash the API key in the config path.** The plaintext key was part of a `core_config_data` path, which leaked it through `bin/magento config:show`, `config:dump`, support exports, and anyone with config-table read access. Use **one shared hash helper** for both the config suffix and the Phase 4 cache id — two implementations drifting means a silent cache miss on every request rather than a visible error. Migration is lossless and needs no re-import: the existing suffix *is* the plaintext key, so a data patch can read `account_settings_<plain>`, write `account_settings_<hash>`, and delete the original. Keep it idempotent; a row already hashed must be left alone.

**2. Clean up orphaned rows.** Collect the hashes of every API key currently configured **across all scopes**, enumerate `account_settings_*`, delete those not in the set. Two hazards: a key configured only at store-view or website scope must not look orphaned (`Service\Settings::hasOwnValue()` and `hasRowAtScope()` already have the partition semantics — getting this wrong deletes live config on exactly the multi-store installs this project targets); and trigger on explicit events only — after a settings import, on API key add/change/remove — not on arbitrary config writes, since a partly-saved form can transiently look like a removal. Log every deletion. Consider report-only first.

**The two facts this plan depends on, as they landed:**

- **Shared hash helper:** `MyParcelNL\Magento\Service\Hash\Fingerprint` (`src/Service/Hash/Fingerprint.php`). `of()` is sha256 as 64 lowercase hex, `isFingerprint()` tells a hashed value from a raw one, and `LABEL_LENGTH` (12) is the prefix to log instead of a whole digest. Dependency-free and deliberately ignorant of what it hashes, so Phase 4 reuses it for the cache id unchanged. Recorded in TR-000007.
- **Config path:** `myparcelnl_magento_general/account_settings_{sha256(apiKey)}`, written at **default scope only** whatever scope the legacy row sat at. Recorded in TR-000005.

Both hazards named above were handled rather than deferred. The migration is `src/Setup/Migrations/FingerprintAccountSettingsPaths.php`, run from `UpgradeData`, idempotent, and it never overwrites an existing fingerprinted row. The orphan cleanup is `Service\AccountSettings\Maintenance::reconcile()`, triggered from `Observer\ConfigChange` and the settings-import controller rather than on arbitrary config writes.

One consequence to carry forward: the fingerprint is the lookup key for rows already stored, so changing `of()` orphans every one of them **and** invalidates the Phase 4 cache. Treat it as a data migration, not a refactor.

**Why it went first:** Phase 5 extends this storage to hold contract definitions. Doing that on the plaintext scheme would have meant writing rows we immediately migrate, and spreading the plaintext key to a second path.

---

## Strategy

Build a **module-owned shipment domain layer**, then swap the SDK underneath it. Both SDK stacks coexist up to beta.21, so Phases 1–8 are developed and verified against the installed SDK with the old stack present as a live reference; Phase 9 flips the pin.

New module code lands under:

- `src/Model/Shipment/` — `PackageType`, `DeliveryType`, `ShipmentOption`, `CountryCode` (constant facades); `Capabilities`; `ShipmentBuilder`
- `src/Adapter/DeliveryOptions/` — module-owned `DeliveryOptions`, `ShipmentOptions`,
  `PickupLocation` value objects and a factory
- `src/Model/Shipment/Type/` — `PackageTypeValue`, `DeliveryTypeValue`: a stored type that can hold a
  value we do not recognise (DR-12)
- `src/Service/Export/` — `ShipmentExportService` (per-key batching), `LabelPdfMerger`
- `src/Service/TrackTraceUrl.php` — provisional: Phase 6c replaces its hard-coded base URL with the API's own links (TR-000005)

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

- All seven SDK package and delivery types are named, not five (DR-12's worked example is why). Verified inert *at the time*: nothing iterates the lists, both admin lists are hardcoded arrays, and an empty weight lookup for a pallet still returns 0. It stopped being inert in Phase 3, where routing the adapter through the facade turned the two extra delivery types into a fix — see DR-13.
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

### Phase 3 — Module-owned delivery options value objects · *Complete*

`Sdk\Adapter\DeliveryOptions\*` (10 classes) and `Factory\DeliveryOptionsAdapterFactory` are replaced by four immutable module classes under `src/Adapter/DeliveryOptions/`: `DeliveryOptions`, `ShipmentOptions`, `PickupLocation`, `DeliveryOptionsFactory`. The SDK's V2 and V3 subclasses become **named constructors**: a stored shape is an input format, not a type, and nothing downstream ever needed to know which one it came from.

`src/Adapter/{DeliveryOptionsFromOrderAdapter,ShipmentOptionsFromAdapter}.php` extended SDK abstracts and wrote their protected properties directly. Both are deleted; their behaviour is now `DeliveryOptions::fromOrderFallback()` and `ShipmentOptions::fromMagentoOptions()`.

Retyped with no logic change: `NeedsQuoteProps`, `Carrier`, `ShippingMethods`, `Block\Sales\View`, `Model\Rest\{OrderDeliveryOptions, Request\OrderDeliveryOptionsV1Request, Transformer\{ShipmentOptionsTransformer, PickupLocationTransformer}}`, `Plugin\Magento\Sales\Api\Data\OrderInformationUpdate`, `Model\Source\DefaultOptions`, and **`TrackTraceHolder`** — which this plan's file list had omitted. `Model\Quote\Checkout` needed nothing: it reaches delivery options only through the `NeedsQuoteProps` trait.

**~~Named `ChosenShipmentOptions`, not `ShipmentOptions`.~~ Reversed, and the reason it was wrong is worth keeping.** `Chosen` was false: `fromMagentoOptions()` reads the admin New Shipment form, `fromCheckoutData()` also reads back the config defaults that `MagentoOrderCollection` writes into the same key, and receipt code, hide sender, large format, age check and label description are never customer choices at all. The class holds the stored option set, whoever decided it, so it is `ShipmentOptions`.

The clash the prefix avoided was not a real constraint. Three same-named classes in three namespaces only collide inside one file's import block, and an alias fixes it — `ShipmentOptionsTransformer` already does exactly that with `use ...OrderApi\Model\ShipmentOptions as OrderApiShipmentOptions`.

**`Helper\ShipmentOptions` became `Service\ShipmentOptionsResolver`, and it now returns the value object.** It was never a second DTO — it resolves what a shipment should get from the posted options, the configured defaults, the country, the carrier and per-product attributes. But it duplicated 12 of the value object's 13 fields, used a second getter vocabulary for the same concepts (`hasReturn` against `isReturn`), handed back an untyped `array`, and re-read the raw order JSON that `DeliveryOptions` had already parsed. `TrackTraceHolder` was holding both objects to describe one shipment.

So: one value object, one resolver that returns it. `getShipmentOptions(): array` is now `resolve(): ShipmentOptions`; the resolver takes `DeliveryOptions` instead of the order column; the value object's three `is*` getters are `has*`, matching the resolver and every other option getter; and `ShipmentOptions::resolved()` is the named constructor for a decided set. The resolver's aliasing constants are gone — `Model\Shipment\ShipmentOption` was already the single source, and the templates that the earlier plan claimed needed the aliases were using `ShipmentOption::` all along. TR-000005 is amended to match.

**`MagentoOrderCollection::setFulfilment()` is deliberately untouched.** Its adapter goes into `FulfilmentOrder::setDeliveryOptions()`, which type hints `AbstractDeliveryOptionsAdapter` at beta.15, so a module value object cannot be passed there at all. Phase 8 owns the fulfilment path. That leaves exactly one SDK delivery-options reference in the module, commented at the call site, and the Phase 3 grep check allows it.

**The DR-12 value objects landed** as `src/Model/Shipment/Type/{AbstractTypeValue, PackageTypeValue, DeliveryTypeValue}`, reachable from `DeliveryOptions::packageTypeValue()` / `deliveryTypeValue()`. They answer three states a caller has to be able to tell apart — absent, stored-but-unresolvable, resolved — and `toApiValue()` passes an unknown *id* through while refusing an unknown *name*, per TR-000005. **Their consumer is Phase 6**; here they are covered by their own tests and hold the two types inside `DeliveryOptions`. `TrackTraceHolder::getPackageType()` and `DeliveryCosts::getBasePrice()` keep substituting a default, because failing a single shipment legibly is the `ShipmentBuilder`'s job and doing it earlier would fail a whole mass action on one bad order. **So Phase 2's logging stopgap in `DefaultOptions::getPackageType()` is not removed here** — that moves to Phase 6. ~~Removed at Phase 6.~~ **It stays, and calling it a stopgap was the error.** It is a read path — the configured default, reached only when nothing was stored — and FR-000010 sanctions exactly that: a substitution that keeps a page rendering, logged with the unresolved value. What Phase 6b removed is the export path's *reliance* on it; a stored type that cannot be resolved now fails the shipment instead.

**Two behaviour changes, and both are fixes.** DR-13 and DR-14.

**Absorbed into this phase while doing it:**

- The three REST fixtures in `Tests/Helpers/` were Mockery doubles over the SDK abstracts. Final value objects cannot be mocked, and value objects should not be: the file is now `DeliveryOptionsFixtures.php` and builds real objects. Overrides stay keyed by getter name so the three test files kept their vocabulary. Two consequences worth knowing. The "full" fixture was returning a pickup location alongside standard delivery, which no order can hold; it is a pickup now. And `PickupLocationTransformerTest`'s null-country case tested a state no named constructor can produce — it now asserts the reachable one, that an unset country stays empty rather than being given a value.
- `Str::snake()` still does the nested-key conversion. It survives at beta.31 (TR-000005) and re-implementing it would have been a second copy of a cache-backed regex.
- Where the SDK read a required pickup key unguarded and ended in a `TypeError` from a getter, the module throws `InvalidArgumentException` naming the missing key. **Not neutral at every call site, and the difference is an improvement worth knowing about.** A `TypeError` is an `Error`, so it walked straight through `OrderInformationUpdate`'s `catch (\Exception)` and fataled Magento's own order REST API on a malformed pickup order; an `InvalidArgumentException` is caught there, logged, and the order is served without the attribute. The other four call sites catch `Throwable` or nothing, so they behave as before.

**Check.** `vendor/bin/pest` green — **355 passed, 2 todos, 0 failures**, up from 298; the two todos are still the pre-existing bugs that go green in Phase 6. The guard is `Tests/Unit/Adapter/DeliveryOptions/DeliveryOptionsEquivalenceTest.php`, which builds the SDK adapter and the module object from the same stored data for nine shapes — current checkout, a `toArray()` round trip, legacy `time`-based, pickup and not, unknown types — and compares `json_encode($x->toArray())`. Encoded, not array-compared: key order is part of the format and an array comparison does not see it. Deleted at Phase 9 with `ConstantEquivalenceTest`.

`OpenApiConformanceTest` and the five transformer tests pass with no change beyond the fixture rename and the one case above, so the versioned REST v1 response is unmoved. The grep below returns only `MagentoOrderCollection`:

```bash
grep -rnE "Sdk\\\\Adapter\\\\DeliveryOptions|DeliveryOptionsAdapterFactory" \
  --include="*.php" --include="*.phtml" src Controller Tests view
```

`setup:di:compile` still needs a vendor-free install. `ShipmentOptionsResolver` takes one more constructor argument than `Helper\ShipmentOptions` did, and it is constructed with `new` at both call sites rather than injected, so DI has nothing to regenerate for it — but the compile is a real check here rather than a formality.

### Phase 4 — Capabilities layer

**Split into three commits**, along the seams [FR-000008](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md) names. Its fourth seam, the DI cleanup, left for Phase 2 under DR-10.

| | | |
|---|---|---|
| **4a** | Client, cache, module value objects | *Complete* |
| **4b** | Admin *New Shipment* form | *Complete* |
| **4c** | Checkout, multicollo, type lists | *Complete* |

`src/Model/Shipment/Capabilities`: module-owned, API-key-scoped, cached. ~~Calls `postCapabilities` directly~~ — calls the endpoint directly, without `ShipmentApi`, per DR-15 and DR-16. Still not `CapabilitiesService` (DR-1, DR-2). Full specification in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md), amended by those two records.

Touches `view/adminhtml/templates/new_shipment.phtml` (heaviest), `src/Block/Sales/{NewShipment,NewShipmentForm}.php`, `src/Model/Quote/Checkout.php`. ~~`src/Helper/CustomsDeclarationFromOrder.php`~~ — **wrong, it needs no change.** Its only country reference is `CountryCode::CC_NL` at `:116`, a static country fact that FR-000008 criterion 5 keeps in the module. The entry was probably meant for `getLocalCountryCode()`, which moved to Phase 8.

**What 4a landed.** Six classes and one XML file: `Capabilities\{Client, Repository, CapabilitySet, CarrierCapability, OptionSet}`, the `Model\Shipment\Carrier` name facade, and `etc/cache.xml` with its `Model\Cache\Type\Capabilities` type class. `V2_NAMES_MAP` went onto `PackageType`, `DeliveryType` and `Carrier`, and `V2_KEYS_MAP` onto `ShipmentOption`; `Tests/Unit/Model/Shipment/V2NameMapTest.php` pins the option half by round-tripping every entry through the SDK's own `mapToCoreApi()`, because `CapabilitiesMapper::KNOWN_OPTION_SETTERS` is private and a second copy of it is exactly what would drift. Invalidation hangs off `Observer\ConfigChange` and the settings-import controller. No consumer changed, so nothing could regress.

~~**One call serves a page.**~~ **Wrong — see DR-18.** The response is `results[]`, each entry carrying `carrier`, `contract`, `packageTypes[]`, `deliveryTypes[]`, `options{}`, `collo{max}`, but a request carrying only a country returns a *superset grouped by carrier*, not a matrix: one result covers several package types and carries the union of their options. Anything that varies per package type has to be asked with `packageType` set. Caching is per request shape, so the cost is cold calls rather than warm ones.

**Deliberately not done in 4a**, each recorded so it does not read as an oversight: the `EnumFallback` listener moved to Phase 6b (DR-16); no `InsuranceRange` class, because `OptionSet` keeps every key verbatim and Phase 5 adds the typed accessor when it has a consumer; no second cache entry for serve-stale, because TR-000007 lists no TTL, so an entry survives a failed refresh by construction; and the three REST transformers keep their own name maps rather than reading the new shared ones — they bind to **Order API** enums while capabilities is **Core API**, and sharing would silently give `upsstandard` the `UPS_STANDARD` mapping it lacks today, changing a shipped versioned response from inside a capabilities phase. That one deserves its own change with its own test.

**Dissolve the fixed type lists here.** `PackageType::IDS` / `NAMES` and the `DeliveryType` equivalents are transitional: "which types exist" becomes what capabilities reports per account. What survives permanently is the *name* map, because the module's snake_case names are `core_config_data` path segments, sit in every historical order's delivery-options JSON, and are the checkout widget's protocol — none of which we control. The list is not an allow-list and must not become one (FR-000010). This is also where the v2→module name translation lands; `PackageTypeTransformer::LEGACY_NAME_MAP` should then read from it rather than holding a second copy.

**What 4b landed.** `new_shipment.phtml` constructs no SDK object at all now: `getCarriers()`,
`getPackageTypes()`, `getShipmentOptions()` and `hasInsurance()` on `Block\Sales\NewShipment` answer
from one `CapabilitySet`, fetched once per render on the order's store and destination.
`NewShipmentForm` is reduced to what it always really was — a bag of human labels — and
`getCarrierSpecificAbstractConsignments()` is gone. `consignmentHasShipmentOption()` became
`hasShipmentOption(carrier, packageType, option)`; its NL / BE+PostNL / UPS-EU ladder went whole,
and the receipt-code standard-only guard stayed, because that one is our rule rather than the
carrier's (DR-14).

**Three merchant-visible changes, all of them the point of the phase.** Outside NL the old ladder
returned false for every option, so a non-NL order got *no* shipment options at all; it now gets
whatever the account's contract carries. Digital stamp was hidden outside NL and mailbox outside NL
for every carrier but PostNL; both are now the account's answer. And a carrier the account has no
contract for no longer appears on the form.

**Insurance is the one carve-out.** `hasInsurance()` reads capabilities, but the amount list still
comes from `NewShipment::getInsurancePossibilities()`, which builds a throwaway consignment. That is
the last SDK probe on this form, it is confined to one method behind a `@todo`, and Phase 5 replaces
it with the contract-definition range (FR-000009).

**Two findings from a live account**, worth more than the stub could give:

- The API returned **`CHEAP_CARGO` and `UPS_EXPRESS_SAVER`** — carriers with no entry in
  `Carrier::V2_NAMES_MAP` and no config path. Logged at notice and skipped, exactly as designed, and
  `NewShipmentCapabilitiesTest` now pins that case with those real names.
- It also returned the option key **`saturdayDelivery`**, which `ShipmentOption` has no constant
  for. Deliberately still unmapped: the module has `EXTRA_DELIVERY_SATURDAY` as a checkout *extra*,
  there is no `dynamic_settings.json` fee or active flag for a saturday shipment option, and no
  field on `ShipmentOptions` to carry it to export — so mapping it now would render a checkbox that
  goes nowhere. It stays logged, and whether it becomes a real option is its own decision.

**Carrier lists.** `Carrier::ALLOWED_CARRIER_CLASSES` goes: 4b moves `NewShipmentForm` off it, 4c moves `Block\System\Config\Form\DeliveryCostsMatrix` off it, and both read `array_keys(Config::CARRIERS_XML_PATH_MAP)` instead — which also removes the GLS asymmetry, since the class list omits GLS while the path map includes it. `CARRIERS_XML_PATH_MAP` itself **stays hardcoded and is not capability data**: it is a carrier-name → config-path map, so a carrier with no path has no fee, no active flag and no drop-off days, and `UpgradeData.php:768` iterates it inside a migration that must run offline. Capabilities decides which of those carriers to *show*; a carrier it reports that we have no path for is logged at notice, which is FR-000010's early-warning signal that a new carrier needs a module release for its settings. `DeliveryCostsMatrix` is admin configuration with no shipment in hand, so its own filtering belongs to Phase 5's contract definitions, not here.

**Two items moved out of this phase.** Dropping the `BaseConsignment` DI argument from `src/Block/Sales/{OrderAction,ShipmentAction}.php` is now **Phase 2**; re-sourcing `getLocalCountryCode()` at `MagentoOrderCollection.php:425` is now **Phase 8**. Neither is capability data — the first is a dead constructor argument, the second a static country fact that TR-000005 routes to module constants — and doing them earlier keeps `di:compile` and the PPS path healthy through more of the branch.

**SDK issues to raise — manual.** Three separate issues on `myparcelnl/sdk` from this phase, plus two found at Phase 6b. Kept in one table so they are raised together:

| # | Issue | Severity | Notes for the write-up |
|---|---|---|---|
| 1 | `HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | **Broken for every consumer on beta.25–31** | The user-agent string passes the null/empty-array guard and is `jsonEncode`d into the body as a bare JSON string, while the request model goes to `ObjectSerializer::toHeaderValue()`. Valid JSON of the wrong shape, so a confusing API-side 4xx rather than a clear local error. Ask for a regression test exercising `HttpCapabilitiesClient` itself — the coverage gap is why it shipped. |
| 2 | `HttpCapabilitiesClient` / `CapabilitiesService` accept no API key, **and** three factories silently fall back to `getenv` | Blocks all library use; the fallback is a wrong-account hazard for every consumer | Two asks in one issue. First, an appended optional constructor arg on both (non-breaking). Second — the more serious one, and the one to lead with — the three factories' environment fallback. Both the argument and the two-line patch are written out in TR-000007's defect 2; copy them from there rather than restating. |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` drops all option values | Design question, not a bug | Propose rather than prescribe: `CapabilitiesResponse` is `final` with a 6-arg positional constructor the SDK's own test calls positionally, so adding insurance means a 7th positional arg or exposing the raw models. Note our client is close to the latter — that may speed agreement. |
| 4 | `ObjectSerializer::toPathValue()` rawurlencodes the documented `;` shipment-id separator | **Multi-label fetching is broken for every v11 consumer** | Raised at Phase 6b, not Phase 4. `GET /shipment_labels/{ids}` receives `%3B` and answers HTTP 500 with `errors:[{"code":400}]`. The spec is explicit — `components/parameters/shipment_ids` says *"Separate multiple shipment IDs using `;`"*, example `"1;2"` — and the SDK joins correctly; only the generated path substitution encodes it. beta.15's hand-built URL (`MyParcelCollection::setPdfOfLabels():517` → `MyParcelRequest::createRequestUrl():392`) sent it raw, so this arrived with the generated client. A single id has no separator, which is why one label always worked. The same applies in the **query**: `ShipmentLabelsService` joins A4 positions with `;` and `ObjectSerializer::buildQuery()` encodes it to `positions=1%3B2%3B3%3B4`, which the endpoint refuses with the same 500 — legal percent-encoding per RFC 3986, so the API's parser evidently splits before decoding. Worked around (path and query both) in `Service\Export\LabelHttpClient`; delete that when this lands. |
| 5 | `ShipmentCreateService::parseCreateResponse()` ignores the response's `secondary_shipments` | **Multicollo consumers cannot learn the ids or barcodes of colli 2..N** | Found at Phase 6b review. `splitShipment()` gives the main shipment and every `SecondaryShipmentRequest` the same reference identifier, and the create response nests the secondary ids (each with `id`, `parent_id`, `barcode`) under the main shipment's `secondary_shipments` — which `parseCreateResponse()` never reads, returning only the main id. Until the SDK exposes them (or a consumer re-queries by `multi_collo_main_shipment_id`), an N-collo shipment yields one stored id and one barcode module-side; the observer's single-track multicollo path documents this. |

Reference `UPGRADE.md`'s claim that `CapabilitiesService` is the v11 answer for capabilities, since 1–3 together make it untrue in practice. When they land, delete the workaround behind its `@todo` and reassess whether this layer can shrink. Issue 4 is independent of the capabilities layer — `Client.php:38`'s `@todo` deliberately still says *issues 1-3*.

**Check:** `setup:di:compile` succeeds. The admin New Shipment form renders the same package types, delivery types and shipment options as on beta.15, per carrier (insurance changes shape in Phase 5). Checkout delivery options unchanged. A cold checkout makes at most one capabilities call per (account, request shape); a warm one makes none.

**Fixed after 4b review.** The form asked one package-type-agnostic question and read it as a
matrix, so every package option showed on a mailbox. DR-18 has the mechanism and the cost. The fix is
confined to `NewShipment`: `getCapabilities(?string $packageType)` memoises one set per shape, the
enumerating readers keep the broad answer, and `getShipmentOptions()`, `hasShipmentOption()` and
`hasInsurance()` ask per package type. `NewShipmentCapabilitiesTest` pins it with a broad superset
and a narrowed mailbox answer that disagree; both new cases fail on the old lookup and no other case
moves.

**Reliability added after review.** A 429 was indistinguishable from a 500: fail open, nothing
cached, so every reload repeated the whole per-package-type burst — the amplification a rate limit
exists to stop. Three changes, all in 4a's layer and specified in TR-000007: one retry honouring
`Retry-After` (capped at 2s, and a timeout is never retried); a 60-second negative cache entry per
failed shape, checked after the success entry so serve-stale still wins; and an inline warning on the
form when any answer was a fallback, because a partial failure otherwise renders at mixed fidelity
with only the log to say so.

`new_shipment.phtml` was rewritten around `NewShipment::getFormCarriers()`, which resolves the whole
form once so the notice can precede it. The markup is unchanged by design — `new-shipment.js` walks
`element.parentNode.parentNode` to find the `data-for_mypa_carrier` wrapper, so the nesting depth is
load-bearing, and the emitted attribute, id and class names were diffed against the previous version
to prove it. The template lost 45 lines and every `NAMES_IDS_MAP` lookup, and now escapes its output.

Two latent defects fixed in passing: `NewShipment::$weightService` and `$configService` were dynamic
properties, deprecated since PHP 8.2 on a class built for every admin shipment page; and
`Tests/Helpers/OrderMocks.php` stubbed no `getWeight()`.

**What 4c landed.** `Model\Quote\Checkout` takes the `Capabilities\Repository` and answers from it:
`checkPackageType()` asks the shape-agnostic question, because it is what *decides* the package type,
and `getDeliveryData()` asks with that package type set. Two calls cold, zero warm.
`MagentoCollection::canUseMultiCollo()` reads the reported collo maximum, keyed on the consignment's
own API key so it needs no store lookup — Phase 6 retypes the parameter and will pass the key
alongside. `DeliveryCostsMatrix` moved off `Carrier::ALLOWED_CARRIER_CLASSES`, which is now deleted
along with the seven SDK carrier imports it needed. `PackageType`/`DeliveryType` lost `IDS`, `NAMES`,
`isValidName()` and `isValidId()`; the two equivalence tests derive from the surviving name map.

**Three deliberate asymmetries.**

- **Multicollo fails *closed*.** An unknown collo maximum answers false, unlike everything else in
  this layer. It is not a feature being offered to a customer; it is a choice between two ways to
  export, and separate consignments are the branch that cannot fail.
- **Monday delivery has no capability to read.** The v2 options object carries `saturdayDelivery` but
  no monday equivalent, so configuration alone decides it now. Merchant-visible: monday delivery can
  appear for a carrier whose config enables it, where the old SDK rule allowed PostNL only. The
  per-carrier setting defaults off, so the practical reach is small.
- **`Checkout.php:420` and `:91` are untouched.** The first picks between `mailbox/active` and
  `mailbox/international_active` — both merchant settings, so the country test is not asking what a
  carrier can do. The second is a country default.

**One thing the plan had wrong.** It said `IDS`/`NAMES` were referenced only by the equivalence test.
`Service\Weight::getEmptyPackageWeightInGrams()` used `isValidId()` too — as a guard against
`nameFromId()` throwing, not as an allow-list. It now uses `nameFromIdOrNull()` and returns 0 for a
name it has no config path for, which is the same behaviour in one step instead of two.

**Digital stamp weights, found in review.** The New Shipment form and the admin default-weight
setting held **separate** lists. v5 merged the 50-100 and 100-350 ranges into one 50-350 range that
sends **200** — a weight inside the range rather than on its boundary — and
`Setup\Migrations\ReplaceDpzRange` rewrote any stored `100` or `350` to `200`. The form was never
updated, so it kept offering both retired values, and `TrackTraceHolder.php:228` passes whatever it
posts straight to the shipment.

`Model\Shipment\DigitalStampWeight` is now the single declaration; the source model and the form both
read it. `value` is deliberately not the upper bound, so a range is matched on `max` and sent as
`value`. **Merchant-visible:** an order between 50g and 350g now sends 200g where it previously sent
100g or 350g. The two dead labels were dropped from the locales that had them.

**Verification state at 4c close.** `vendor/bin/pest` green — **458 passed, 2 todos, 4 risky, 0
failures**, up from 449. `setup:di:compile` succeeds with `Checkout`'s new constructor argument. The
probe grep over `src`, `Controller` and `view` returns **zero** for `canHave*` and `getAllowed*`; what
remains is `getInsurancePossibilities` (Phase 5), `isToRowCountry` (Phase 6) and `getLocalCountryCode`
(Phase 8), each in the phase that owns it.

One test-writing trap worth recording: `AbstractConsignment::getCarrierName()` is `final`, so a
Mockery double silently runs the real method and answers null for every carrier. The multicollo tests
build real consignments through `ConsignmentFactory` instead.

**Verification state at 4b close.** `vendor/bin/pest` green — **430 passed, 2 todos, 4 risky, 0
failures**, up from 418. `setup:di:compile` succeeds on a vendor-free install. The probe grep over
`src/Block`, `Controller` and `view` returns zero, and the only `MyParcelNL\Sdk` references left on
the form path are `CapabilitiesRequest`, which is the SDK DTO we are meant to use, and the
`ConsignmentFactory` behind the insurance `@todo`. Every `$block->` and `$form->` call in the
template was checked against the blocks' public API, since a template typo is invisible to both the
test suite and `di:compile`.

**Verification state at 4a close.** `vendor/bin/pest` green — **418 passed, 2 todos, 4 risky, 0 failures**, up from 378; the todos and the risky four are pre-existing. `setup:di:compile` succeeds on a vendor-free install. `cache:status` lists `myparcelnl_capabilities` and `cache:clean myparcelnl_capabilities` flushes it by its own tag. DR-17's migration was verified both ways on a real install: with the key removed from `env.php`, `setup:upgrade` re-enables it; with the type explicitly disabled, `setup:upgrade` leaves it alone.

### Phase 5 — Insurance as a range, contract definitions, account settings

**Split into two commits**, along the seam the two FRs already draw.

| | | |
|---|---|---|
| **5a** | Contract definitions replace carrier options | *Complete* |
| **5b** | Insurance as a range | *Complete* |

Insurance becomes a free amount between `min` and `max` (DR-4), bounded per DR-19. Specification in [FR-000009](../functional-requirements/FR-000009-insurance-as-a-range.md) and [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

- `src/Model/Source/CarrierInsurancePossibilities.php` and the **17 virtual types at `etc/di.xml:75-196`** exist only to populate tier dropdowns, so they go. Only 16 of them are referenced by a setting, so one carrier-and-zone virtual type is already orphaned — identify which while removing them, since it may mean a missing setting rather than a dead type. Convert the 16 `source_model` references in `etc/dynamic_settings.json` (lines 1117, 1128, 1139, 1150, 1845, 1856, 2200, 2211, 2222, 2233, 2504, 2515, 3284, 3520, 3530, 3540) from a select to a numeric field validated against `[min, max]`.
- `DefaultOptions::getInsurance()` (`src/Model/Source/DefaultOptions.php:163`) snaps to the nearest tier today; the snapping goes and the clamp lands one layer out, in `ShipmentOptionsResolver`, so there is exactly one clamp for both the resolved default and a posted admin override.
- **Read the flat `min` / `max` / `default`** on the insurance option — confirmed populated by the API. Not the nested `insured_amount` wrapper, which the spec marks deprecated. PDK still reads the wrapper; that is PDK being behind, and worth a heads-up to that team since the wrapper is slated for removal.
- ~~`src/Setup/UpgradeData.php:946,981,994,1012`~~ — **wrong line numbers, and one call too many.** There are **three** calls: `:999` (NL), `:1012` (BE), `:1030` (EU). ROW never calls it; `:1034` hardcodes `0`. They sit inside a **historical data migration**, so the tier lists they need are frozen as module constants — it must not start depending on a network call.
- ~~Delete the broken imports at `AccountSettings.php:13,15`.~~ **Not broken.** All three `Model\Account\*` classes load on beta.15; they are live couplings this phase removes, not current breakage.
- ~~Retire the hand-rolled `createArray()` (`@TODO sdk#326` at `CarrierConfigurationImport.php:132`).~~ **Moved.** #967 lifted `createArray()` into `Service\AccountSettings\Importer.php` and dropped the TODO comment with it; the controller is 99 lines and delegates.

**What 5a landed.** `Client` gained `sendContractDefinitions()`: a second path, a `{carrier}` body and
an `items` envelope over the same auth, `Accept` header, retry ladder and host override. The endpoint
is posted to directly, like capabilities, because `postCapabilitiesContractDefinitions()` carries the
same reversed-argument defect (DR-15). `Importer` now fetches one contract definition per carrier in
`Config::CARRIERS_XML_PATH_MAP` and stores the flattened `items` verbatim under
`contract_definitions`, replacing `carrier_options`; a carrier the account has no contract for is
logged at notice and skipped rather than failing the import. `Service\AccountSettings\ContractDefinitions`
reads that row back as a `CapabilitySet` — constructor-injected, so unlike `AccountSettings` it is
testable.

**The account half is untouched, and that was the finding.** `AccountSettings` had exactly one live
reader: `PackageRepository.php:135-137` → `getAccount()->getGeneralSettings()->hasPostnlMailboxInternational()`.
That flag lives on the account's general settings, which no contract carries, so `AccountWebService`
and `getAccount()` stay. `getShop()`, `getCarrierOptions()` and `getCarrierOptionsByCarrier()` had
zero callers between them and were deleted outright — which also removes the uninitialised
typed-property `getShop()` would have thrown on.

**Two things fixed in passing.** `DeliveryCostsMatrix` now filters by contract definitions, closing
the `@todo` 4c left it, and fails open to every configured carrier.

And the *Import MyParcel Backoffice settings* button answered a 500 to an AJAX call that read nothing
at all, so an invalid key produced a spinner that simply stopped. The controller now catches and
answers, and the button was rebuilt to match `ApiAccessTokenButton`: a `data-mage-init` root and a
RequireJS module in `view/adminhtml/web/js/account-settings-import.js`, replacing an inline script
that used Prototype's `Ajax.Request`. The outcome is reported through Magento's own inline admin
message markup rather than a `window.alert`, since the import runs beside a form the admin is still
filling in. The scope is resolved by the block through `Settings::getCurrentScopeFromRequest()`
instead of being parsed out of `window.location.pathname` in JavaScript; `App\Config::getValue()`
normalises `store`/`website` to their plural forms itself, so that swap changes nothing about which
key is read.

**Production mode needs `setup:static-content:deploy` for the new module**, which is in the standard
rebuild below but easy to skip when only a `.js` file moved.

**Check at 5a close.** `vendor/bin/pest` green — **481 passed, 2 todos, 0 failures**, up from 466 (466
itself is 458 plus the two 4c test files that were never committed). `setup:di:compile` succeeds with
`Importer` and `DeliveryCostsMatrix` both carrying new constructor arguments.

**What 5b landed.** `Capabilities\InsuranceRange` owns the cents-to-euros conversion in one place and
reads the flat `min`/`max`/`default` properties only. It rounds inwards, so a fractional bound can
never widen a range.

**One clamp, not three.** The plan said `DefaultOptions::getInsurance()` would become the clamp.
Instead its tier loop went and the clamp moved one layer out, to
`ShipmentOptionsResolver::getInsurance()` — the single funnel through which both a posted admin
amount and the resolved configuration pass. `DefaultOptions` now answers what the merchant's
configuration asks for (`min(order value × percentage, cap)`, rounded up) and nothing more. It gained
the `getStoreId()` argument `hasOptionSet()` already passed and it did not, without which a
multi-store install resolved the cap against the wrong store.

**The settings screen.** All 16 selects became number fields behind a new `frontend_model`,
`Block\System\Config\Form\InsuranceAmount`, which states the contract range in the field's comment
and emits Magento's own `validate-number-range` class.

Enforcement on save is a **validator**, not a branch in the observer. `Model\Settings\Validator\SettingValidatorInterface`
has two methods — `handles(string $path)` and `validate()` — and `Observer\ConfigChange` takes an
array of them from `etc/di.xml`, asking each whether a path is its business before asking it to judge
anything. The next validator is one `<item>`; the observer keeps no per-setting knowledge at all.

**The path gate is a named method, on purpose.** The first cut hid it inside
`ContractDefinitions::insuranceRangeForPath()`, whose null meant both "not an insurance path" and "no
bound resolvable" — two unrelated answers a caller could not tell apart, in a class that had no
business knowing `core_config_data` path shapes. Now `Model\Settings\InsuranceAmountSetting::carrierFor()`
owns the path knowledge for both the validator and the settings field, and `ContractDefinitions`
answers per carrier.

A rejection costs **one field**: every field is posted on every save, so failing the lot would let one
bad amount block every other change. The validator also refuses a value that is not a whole number of
euros — accepting `abc` would save it and every reader coerces it to `0`, switching insurance off
silently. A cleared field is judged as `0` for the same reason, which is also what stops clearing the
box being a way around a contract that requires insurance.

**Zero is governed by `is_required`, not by the minimum.** This took two corrections, both worth
recording because the wrong answers were each plausible.

The first draft treated the permitted set as `{0} ∪ [min, max]`, reasoning that zero is how a merchant
switches insurance off. That was inferred from the module rather than from the contract, and a
domain check said zero is not accepted when the minimum is above zero. The second draft therefore made
the set exactly `[min, max]` — which is right about amounts and wrong about opt-out: it took away a
merchant's ability to not insure at all, for a carrier whose contract merely sets a floor on insured
value.

**Settled:** a contract minimum bounds what an *insured* parcel may be insured for; whether a parcel
must be insured is `is_required`'s answer, and contract definitions already carry it. So the permitted
set is `[min, max]` for a compulsory contract and `[min, max]` plus zero for an optional one. An amount
between zero and the minimum is refused either way. An option that does not state `is_required` counts
as optional, so a silent contract cannot cost a merchant their opt-out.

`InsuranceRange::allows()` and `lowestAccepted()` are the single statement of that rule; the settings
field, the New Shipment form and the save observer all ask rather than restate it. The browser can
express only one span, so an optional contract's span starts at zero and the observer catches the gap
between zero and the minimum.

Zero on the export path is not an amount at all: it means the insurance option is omitted from the
request, which is why both encoders guard on it before writing anything. An order below the configured
`insurance_from_price` still ships uninsured whatever the contract says — and if the contract required
insurance, the API refuses it, which is the visible failure DR-12 prefers over a silent substitution.

**Two config gaps, found by running the old SDK rather than reading it.** GLS's Belgium tier list is
*empty* at beta.15, which is why the orphaned `\GLS\Belgium` virtual type never got a setting: the
dropdown would have offered only `0`. With the bound coming from the account, the field is meaningful,
so it exists now — 17 settings for 17 combinations, and one new field on the GLS tab.
`myparcelnl_magento_ups_settings` had no insurance defaults at all despite carrying a setting; added.

**`UpgradeData` no longer references the SDK at all.** `grep MyParcelNL\\Sdk src/Setup/UpgradeData.php`
returns nothing. The three tier lookups and `$compareAmountWithTiers` moved to
`Model\Shipment\LegacyInsuranceTiers`, whose lists were extracted by running
`getInsurancePossibilities()` against beta.15 for all eight configured carriers and four zones, and
whose test asserts they still match while the old SDK is installed. The rounding rule moved with them
rather than being copied, so the migration and the DR-20 shim cannot drift.

**A bug found and deliberately not fixed.** `$getFromPriceFunction` (`UpgradeData.php:919-940`) closes
over an undefined `$insuranceFromPriceArray`, so it always returns a fresh single-element array and
the caller then nests arrays that `sort()` compares as arrays. It is real, and fixing it inside a
historical migration would change rows on installs that already ran it. Left alone on purpose.

**Verification state at 5b close.** `vendor/bin/pest` green — **534 passed, 2 todos, 0 failures**, up
from 481. `setup:di:compile` succeeds with 17 virtual types and a source model deleted and two classes
carrying new constructor arguments. The probe grep over `src`, `Controller`, `etc`, `view` and `i18n`
returns only `LegacyInsuranceTiers` and its own equivalence test.

One test-writing note worth recording: `Magento\Backend\Block\Template` is an empty stub under the
unit suite, so a block test declares the two inherited accessors it needs on a subclass rather than
reflecting them onto a parent that does not have them. `setPrivateProperty()` now walks the class
hierarchy, which is what makes that work.

**Check:** re-run *Import MyParcel Backoffice settings* and diff the stored JSON against the beta.15 output. Per carrier × zone, confirm `[min, max]` contains every value the old tier list offered — if an old top tier exceeds the contract max, that is a real finding, not a rounding error. An existing saved amount stays valid; an out-of-range one clamps rather than zeroing. Export with an amount that was never a tier (e.g. €137) and confirm the API accepts it. `setup:upgrade` on a pre-migration snapshot still produces identical rows.

### Phase 6 — Shipment building and per-key export

**Merged with the former Phase 7** (DR-23) and **split into three commits**.

| | | |
|---|---|---|
| **6a** | Test harness: grow the accessors, pin the homeless rules | *Complete* |
| **6b** | `ShipmentBuilder` and `ShipmentExportService` — the swap | *Complete* |
| **6c** | Merged label PDF, track & trace links from the API | |

`src/Model/Sales/TrackTraceHolder.php` → `src/Model/Shipment/ShipmentBuilder`, producing an SDK
`Shipment`, and `MagentoCollection::$myParcelCollection` → `src/Service/Export/ShipmentExportService`.
Specification in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).

**The pass/fail signal needs restating.** The plan said "the Phase 1 tests still pass unchanged".
Taken literally that cannot hold: four of the six `TrackTraceHolder*` tests reach private methods by
name through `invokePrivateMethod()`, and two more read `$holder->consignment` directly, so deleting
the class makes them **error** rather than fail — a stack trace carries no behaviour signal. The rule
is therefore: **the assertions are unchanged; only the subject construction moves.** An edit to an
`expect(...)` line is what needs justifying. `MagentoCollectionMultiColloTest` is a sanctioned edit,
since this phase retypes the method it exercises.

**What 6a landed.** `Tests/Helpers/ShipmentAccessors.php` grew from two facts to eight, plus
`customsItemValue()` — the one customs getter whose *shape* changes, since `item_value` becomes a
`RefTypesMoney`. The weight and customs tests now read through it. Two gaps were filled: the rule
that **an age check forces `PackageType::PACKAGE`**, which lives only in
`TrackTraceHolder::getPackageType()` and had no test, and `ShipmentOptionsResolver::resolve()`'s
non-null contract, which nothing covered — `ShipmentOptions::resolved()` is a bare constructor call
that enforces nothing and every getter is declared nullable, so that guarantee lived in one caller's
convention. 551 → 555 passing, no other test touched.

**6b — the builder.**

- `ConsignmentFactory::createByCarrierName()` → `(new Shipment())->setCarrier(...)`
- flat address setters → `setRecipient([...])`. **Not a rename: v11 has no `full_street`.**
  `AbstractConsignment::setFullStreet()` did the splitting; the recipient takes `street` (max 40),
  `number`, `number_suffix` and `box_number` separately. So `Helper\SplitStreet::splitStreet()` moves
  onto the label path, and with it `getLocalCountryCode()` — **moved here from Phase 8**, since this
  is now the first phase that needs it.
- the ~18 option setters → `setOptions(ShipmentOptions)`; `label_description`, `delivery_type`,
  `delivery_date` and `insurance` all live **inside** options at beta.31. Phase 3 left this with
  **one** source for the options themselves: `ShipmentOptionsResolver::resolve()`. It does **not**
  decide package type, delivery type, delivery date or weight — those stay the builder's. Watch the
  namespace collision — alias one at the import, the way `ShipmentOptionsTransformer` does.
- **The `?? false` coercions at `:191-199` change rather than disappear.** Every boolean option is
  typed `RefTypesIntBoolean` and `ObjectSerializer` checks it against `[0, 1]` with a strict
  `in_array`, so PHP `true`/`false` passes the setter and throws at **serialization** time, far from
  the call site. They become `? 1 : 0`.
- **`label_description` throws above 45 characters where it used to truncate.** Both caps are 45, but
  `AbstractConsignment::setLabelDescription()` does `Str::limit($x, 42)` while the generated setter
  throws. Truncate to 42 before setting to preserve behaviour exactly.
- `delivery_date` must match `Y-m-d H:i:s`; `delivery_type` must be a literal `int` in `1..8` (DR-21).
- `setPhysicalProperties(['weight' => …])` — shape unchanged, the one claim that verified clean.
- `addItem(MyParcelCustomsItem)` → `setCustomsDeclaration(...)` — see DR-22.
- the pickup block → `setPickup(...)`. **`setCustomsDeclaration()`, `setPickup()` and `setSender()`
  are not array-normalized** the way `setRecipient()`, `setPhysicalProperties()` and `setOptions()`
  are: they take the generated model or throw. An array is stored raw and comes back raw.
- Fix the double-add at `:346-366` / `:368-386`. **The Phase 1 test for this goes green here.** Same
  for the age-check precedence bug (DR-7), whose fix already exists in
  `ShipmentOptionsResolver::hasAgeCheck()` — routing the builder through `resolve()` gets it free.
- **`$consignment->validate()` (`MagentoCollection.php:414`) has no automatic v11 equivalent.**
  `listInvalidProperties()` and `valid()` exist but are never called on the create path, so a missing
  `recipient.cc` or `physical_properties.weight` would surface as an API error rather than the
  per-order local message it is today. Call `valid()` explicitly.
- The API key is no longer on the shipment; pair each `Shipment` with its key.
- **Never substitute a type we cannot resolve** (DR-12). If a stored delivery or package type has no
  id, fail that shipment with a message naming the order and the value, and leave the rest of the
  batch intact. Silently exporting a different delivery than the customer paid for is the outcome
  this phase must make impossible.
- **The order fallback now reads the date and the pickup location** — see DR-24. It was never a
  decision to drop them.
- ~~**The two export paths disagree about a pickup with no location, and the label path fatals.**~~
  **Half wrong when written, and now decided.** Both sites already caught
  `BadMethodCallException | InvalidArgumentException` — `TrackTraceHolder:131` and
  `MagentoOrderCollection:178` — so neither fataled and the two agreed. The open half was what they
  should agree *on*, and the answer is **refuse**: see DR-25.
- `isToRowCountry()` at `:342` comes from the Phase 2 `CountryCode` constants, not from capabilities.
- **Retype `canUseMultiCollo()`** (`MagentoCollection.php:648`, called from `:441` and
  `src/Observer/NewShipment.php:111`) and **add the API key as a second argument**, since
  `getApiKey()` is what it reads off the consignment today. Keep the rule itself alone.
- **Multicollo has no collo field.** `MultiColloShipmentService::splitShipment(Shipment, int)` builds
  `secondary_shipments` and divides the weight; it takes no API key and must not be built per key.
  `$amount === 1` **throws**, and `NewShipment.php:111` has no `1 < $amount` guard the way
  `MagentoCollection.php:441` does. Guard it.
- **The tightest coupling is `NewShipment.php:143-150`**, which pairs the SDK collection against
  `array_pop($trackTraceHolders)` positionally and reversed. `$trackTraceHolder->mageTrack` is the
  only carrier of the Magento `Track`, so the builder must hand the track back alongside the
  shipment. TR-000006 also forbids correlating by result order — correlate by reference identifier.

**6b — the export service.** Group by resolved API key **value**, not store id; one `ShipmentApi` per
key from a single `ShipmentApiFactory::make()` call site, with an empty key raising
`LocalizedException` before it; chunk at a configurable size defaulting to 20; persist each chunk
before issuing the next; skip an order that already carries a shipment id, because the API
deduplicates nothing. Full specification and the eleven test scenarios in TR-000006.

**6c — labels and links.** `LabelPdfMerger` over `setasign/fpdi`, made an explicit dependency.
`ShipmentLabelsService` holds one PDF string per instance, so cross-account merging happens in module
code; merged output follows the admin's selection order. Then `src/Service/TrackTraceUrl` moves off
its hard-coded base URL to the API's own `link_consumer_portal` / `link_tracktrace`, requested via
`Services\TrackTrace\ShipmentTrackTraceService::fetchTrackTraceData()`. Not a drop-in —
`src/Ui/Component/Listing/Column/TrackAndTrace.php:118` renders one link per grid row and needs a
batched fetch keyed by shipment id plus caching, and
`src/Block/DataProviders/Email/Shipment/TrackingUrl.php` should store the link at export time rather
than fetch it while rendering an email.

**Out of scope here.** `MagentoCollection.php:480-505` `addReturnInTheBox()` and its
`AbstractConsignment` closure are dictated by the SDK's `generateReturnConsignments()` and leave when
that call does.

**Check.** Three layers, none a golden file. First, the Phase 1 tests pass with their **assertions**
unedited, and both `->todo()` markers are gone. Second, TR-000006's eleven scenarios, of which the
load-bearing one is that a re-run over already-shipped orders makes **zero** create calls. Third,
validate the outbound request against the real spec: `league/openapi-psr7-validator` and
`nyholm/psr7` are already dev dependencies and beta.31 ships `openapi/coreapi.yaml`. Caveat — that
file is a ~3KB `$ref` root stitching in `common.yaml` and `commonProperties.yaml`, so resolving the
bundle is real plumbing. Timebox it; if it fights back, read the serialised payload against the spec
by hand once per fixture. Do not add snapshots as a consolation prize.

**What 6b landed.** `TrackTraceHolder` is gone. In its place:
`Model\Shipment\{ShipmentBuilder, BuiltShipment, CustomsDeclarationBuilder, ShipmentValidator}` and
`Service\Export\{ShipmentApiProvider, ShipmentExportService, ExportReport, LabelPdfMerger}`.

**`BuiltShipment` is the piece the plan did not name.** A v11 `Shipment` carries neither the API key
nor the Magento track, and the observer paired them by array position, reversed. Pairing them in one
object is what makes both TR-000006's correlation rule and per-key routing fall out rather than be
arranged.

**Five things the plan assumed and got wrong**, each its own decision record: the pickup divergence
was already closed and only the answer was open (DR-25); the reference identifier could not stay the
bare shipment entity id (DR-26); DR-7 was only half fixed by Phase 3 (DR-27); the customs country
setter cannot be called at all (DR-28); and `Shipment::valid()` does not recurse, so it replaces
almost nothing of what `$consignment->validate()` did (DR-29).

**`LabelPdfMerger` moved up from 6c**, because removing `MyParcelCollection` removes
`setPdfOfLabels()` with it, and leaving the download broken across four commits is what the standing
runnable rule forbids. 6c keeps the track & trace links.

**Three duplications closed while passing through.** `setPdfOfLabels()`, `downloadPdfOfLabels()` and
`setLatestData()` were identical in both collection subclasses and now live once on the base;
`getLocalCountryCode()` is `Model\Shipment\Carrier::localCountryCodeFor()` rather than a throwaway
consignment, which also removes the last `ConsignmentFactory` call outside the fulfilment path.

**Found and not fixed:** the customs `classification` is still cast through `(int)`, which drops the
leading zero of an HS code such as `0901`. Real, pre-existing, and correcting it changes what ships
for every ROW order — so it is carried verbatim and recorded here rather than folded into a
behaviour-preserving port.

**Check at 6b close.** `vendor/bin/pest` green on **beta.31** — **533 passed, 0 todos, 0 failures**. It read 580 on beta.15 before the pin moved; the difference is the 47 parity tests DR-32 deletes, whose subject the bump removes.
Both `->todo()` markers are gone for real: the customs double-add and DR-7. Every assertion in the
six moved test files is unchanged except `ShipmentBuilderWeightTest`'s, which reads the returned
weight where it used to read the mutated consignment — the expected numbers are identical.
`MagentoCollectionMultiColloTest` is the sanctioned retype. `DefaultOptionsPackageTypeTest` was left
alone, because the behaviour it pins turned out to be correct rather than a stopgap — see Phase 3. `setup:di:compile` succeeds. The choke-point grep returns exactly one
`ShipmentApiFactory` call site.

**The DR-20 removal has no automated signal at all** — the shim was never covered by a test. Export a
domestic order insured at **€137**, an amount that was never a tier, and confirm the API accepts it.
That is an exit criterion for this phase, not a nice-to-have.

### Phase 8 — Fulfilment (PPS) alignment · *Not started*

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` now returns SDK `Model\Shipment\ShipmentOptions`, and `getCarrier()` **throws** unless `setCarrierId()` was called. Update `MagentoOrderCollection::setFulfilment()` (`:163-265`).
- `src/Cron/UpdateStatus.php:126` — loop over the distinct API keys of the orders being polled instead of one ambient key.
- Fix `$orderLines` being created once *outside* the per-order loop at `MagentoOrderCollection.php:166`, so lines accumulate across orders in a multi-order batch.
- ~~**Re-source `getLocalCountryCode()`**~~ — **moved to Phase 6.** It sat here because `setShippingRecipient()` is reached only from `setFulfilment()`, but v11's recipient has no `full_street`, so Phase 6's label path needs `SplitStreet::splitStreet()` and therefore the same country source. What stays here is deleting the throwaway consignment at `MagentoOrderCollection.php:425` once the fulfilment path no longer reads it.

**Check:** export two orders from two stores in PPS mode; each lands in the right account with only its own order lines; cron updates both.

### Phase 9 — Remove dead code · *Pin already bumped at 6b*

- ~~`composer.json`: `"myparcelnl/sdk": "11.0.0-beta.31@beta"`, add `"setasign/fpdi": "^2.6"`.~~ **Both done at 6b** — the pin because `ShipmentCreateService::create()` cannot succeed at beta.15 (DR-30), fpdi because `Service\Export\LabelPdfMerger` now uses it directly.
- ~~Delete dead/broken imports: `BaseConsignment`, `CarrierFactory` ×2, `CarrierConfigurationFactory`, `CarrierConfiguration`.~~ **Done in Phase 2**, along with twelve other unused imports found in the same sweep.
- `Model\PrinterlessReturnRequest` constructor is now `(string $apiKey, int $consignmentId)`.
- Carrier `::CONSIGNMENT` constants and `getConsignmentClass()` are gone; `TYPE_B2C`/`TYPE_B2B` moved to `AbstractCarrier`.
- ~~Retire `Tests/Helpers/DeliveryOptionsMocks.php` (dead and SDK-coupled).~~ **Wrong on both counts.**
  Three REST tests use it. Phase 3 rebuilt it as `DeliveryOptionsFixtures.php` over the module
  value objects, so it is live and no longer SDK-coupled. Nothing to retire.
- **Remove `extra_assurance` from `Adapter\DeliveryOptions\ShipmentOptions`.** It has no reader
  anywhere in the module, no `ShipmentOption` constant, no `AbstractConsignment` setter, and no
  entry in `ShipmentOptionsTransformer`'s map. It only survived because `DeliveryOptionsEquivalenceTest`
  compared all thirteen `toArray()` keys against the SDK adapter. **That test is gone (DR-32), so this
  is unblocked** — but `toArray()` key order is a persisted format, so removing a key is a data
  question, not a tidy-up.
- Note in TR-000005 that `AccountWebService`, `CarrierOptionsWebService` and `OrderCollection` are now `@internal`.

**Check:** `composer update myparcelnl/sdk`, then the full verification below.

---

## Traceability: phases ↔ requirements

**The phases are not 1:1 with the FRs, and should not be forced to be.** Phases are ordered by *technical dependency* — constants before value objects before capabilities before shipment building — because that is what keeps every intermediate commit runnable. FRs decompose by *capability*, which cuts across that order. One FR lands over several phases; one phase serves several FRs.

Tracking is this matrix plus each document's own Traceability section (house convention — see BR-000002's). The documents do not reference phases: phases are an artefact of one PR, and the requirements outlive it. This plan is where the two are joined.

| Phase | Implements | Notes |
|---|---|---|
| **Prereq PR** (#967) | — (no docs, by decision) | Merged. Its two hand-off facts are recorded in TR-000007 (hash helper) and TR-000005 (config path) |
| **0** — Plan + requirements | — | Commits this plan, then produces everything below |
| **1** — Tests for our own rules | *supports all* | No FR of its own. Test infrastructure protecting the refactor; inventing an FR would be traceability theatre. Say so in the PR description rather than faking a parent. |
| **2** — Constants and helpers | TR-000005 | Pure refactor, no user-visible capability |
| **3** — Delivery options value objects | TR-000005 | Ditto; guarded by the existing REST conformance tests |
| **4a** — Capabilities client, cache, models | **FR-000010**, TR-000007 | The loose-coupling rules *are* FR-000010's acceptance criteria. No consumer changes |
| **4b** — Admin New Shipment form | **FR-000008**, **FR-000010** | |
| **4c** — Checkout, multicollo, type lists | **FR-000008**, **FR-000010** | |
| **5a** — Contract definitions replace carrier options | **FR-000008** criterion 5 (source half), TR-000007 | |
| **5b** — Insurance as a range | **FR-000009**, **FR-000008** criterion 8, TR-000007 | US-000010 |
| **6a** — Test harness for the rewrite | *supports 6b* | No FR. Grows the accessors and pins two rules that had no test |
| **6b** — Shipment building and per-key export | **FR-000006**, **FR-000007**, TR-000005, TR-000006 | US-000007, US-000009. Former Phase 7 merged in — DR-23 |
| **6c** — Merged label PDF, track & trace links | **FR-000006**, **FR-000007**, TR-000006 | US-000008 |
| **8** — Fulfilment (PPS) alignment | **FR-000006**, **FR-000007** | US-000011 |
| **9** — Bump the pin, remove dead code | BR-000003 | ~~Bump~~ **done at 6b** (DR-30, DR-32). What remains is the dead-code sweep the pin did not force |

Two things this makes visible:

- **Phases 2 and 3 have no FR** — they trace only to TR-000005. Correct for a like-for-like port: there is no new capability to specify, and an FR asserting "behaviour is unchanged" is not usefully testable. Each turned out to carry one visible correction anyway — the EU country list (DR-9) and the short delivery type map (DR-13) — and both are recorded as decision records rather than retrofitted into an FR.
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
12. **A delivery type the old SDK map lacked (Phase 3, DR-13).** Export an order stored with `early_morning` or `same_day`. It ships as that delivery type; before Phase 3 it silently shipped and charged as standard.
13. **Receipt code on a non-standard delivery (Phase 3, DR-14).** Turn receipt code on as the PostNL default. Export an NL **evening** order: it ships without receipt code and keeps signature and only recipient. A **standard** order still gets receipt code. Before Phase 3 the evening order got receipt code and silently lost the other two options. Check the fulfilment (PPS) path as well as the label path — it never had the SDK's pickup guard.
14. **Label description placeholders (Phase 3).** Print a label for an order whose label description uses `%delivery_date%`. The date still renders; the resolver now reads it from the parsed delivery options rather than decoding the order column itself.
15. ~~**Rejection attribution (DR-29, DR-31).**~~ **Done on acceptance 2026-08-28.** The API rejects
    atomically, so the validator stays and chunk size doubles as failure granularity. The response is
    RFC 9457, not the documented shape; the offending order is now named with the API's own sentence
    and the field it objected to, and the other orders in the chunk are told they did not ship
    without being blamed. Re-check only if the `rejection body` log line stops matching the shape in
    DR-31.

---

## Open risks

- **Capability parity (Phases 4–5) is the least certain part**, though the PDK removes most of the design risk. Expect gaps needing an SDK/API answer. Raise them as questions **and** keep moving with a documented assumption recorded in TR-000005 — do not block a phase on an upstream answer, and do not bury the guess either. Where PDK and the OpenAPI spec disagree, trust an observed acceptance response over either.
- **We diverge from the PDK on purpose, in three places** — DR-3, DR-4 and FR-000010 each own one. Enumerated with their reasoning in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md), so nobody "aligns with the PDK" later without re-reading the argument.
- **Loose coupling has a cost to accept knowingly**, stated in [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md): the API error at export replaces the greyed-out checkbox, and the trade only holds while that error reaches the admin legibly. Check explicitly in Phase 6b.
- **Four SDK defects are raised and not fixed by us** — three at Phase 4, a fourth at Phase 6b. Until 1–3 land, `src/Model/Shipment/Capabilities` carries glue duplicating what `CapabilitiesService` should do, and defect 1 means capabilities is broken for every SDK consumer on beta.25–31 — expect other integrations to hit it. Defect 4 is the labels separator, worked around in `Service\Export\LabelHttpClient`. All four are listed in the Phase 4 table and should be raised together before this PR closes.
- The generated `Client\Generated\OrderApi\Model\*` enums the REST transformers bind to are the highest-churn SDK surface; `ShipmentOptionsTransformerTest` asserts `attributeMap()` keys verbatim.
- `MultiColloShipmentService` takes no API key while our capabilities client is per key — the asymmetry that makes per-key client construction easy to get wrong. Rule in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- Worth raising an ADR in [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr) for "the Magento module owns its shipment domain layer", since that boundary is now permanent rather than borrowed from the SDK.
