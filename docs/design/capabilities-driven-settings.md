# Capabilities-driven settings (INT-1289)

> **Status — 2026-09-22.** PR 1 of 6 has landed. The rest is unstarted.
>
> | # | PR | branch | state |
> |---|---|---|---|
> | — | stack base | `feat/use-sdk-v11-shipments` | PR open, not in `main` |
> | 1 | stateless package type services | `refactor/stateless-package-type-services` | done |
> | 2 | derive carrier and option names | — | next |
> | 3 | honour capability option dependencies | — | findings in [capability-option-dependencies.md](capability-option-dependencies.md) |
> | 4 | generate the settings form | — | not started |
> | 5 | supply settings defaults | — | not started |
> | 6 | account-derived export mode and proposition | — | not started |
>
> PR 1 also carried two changes the plan below does not name: the weight unit became one global
> setting (`cb46130c`), and a Dutch msgid was matched to its source string (`591bc3b9`).
>
> Everything after this block is the plan as written, unchanged.

## What the ticket asks

- The settings JSON must become capabilities-based. **This also makes it possible to switch
  proposition.**
- **"Let erop dat dit optioneel per scope kan!!!!"** — per scope, or explicitly blocked if that
  proves impossible.
- Include the new **`no_tracking`** option (INT-1694). **`tracked` disappears from capabilities and
  is replaced by `no_tracking`.**
- **'order v1' is no longer a setting**; it comes from the account data.

Acceptance:

- `no_tracking` is shown from capabilities, so only for the package types that have it.
- There is always tracking where available; `no_tracking` is an explicit **opt-out**.
- **No surcharge can be attached to `no_tracking`** — so no `_fee` field.

Tester note: full regression together with INT-1286, and especially scopes — bulk export of stores
across different scopes **and different propositions**.

Per-scope is the shape of the whole plan. Proposition switching needs one call site. `no_tracking`
needs no new name and no SDK release — only the fixed option key lists opened up, which the
dependency work needs anyway.

## Context

The module's admin settings are a fixed 160 KB file, `etc/dynamic_settings.json`: 9 sections, 263
fields, one section per carrier, written by hand. Its own header says *"auto-generated from
system.xml. It can be modified or replaced with API responses."* That replacement is this ticket.

The module already knows each account's real carriers, package types, delivery types, options and
insurance bounds. `docs/sdk-v11.md:23` states the rule: *"An account's real set comes from
capabilities, never from either list."* The settings screen does not obey it.

The problem is written into the code. `src/Block/Sales/NewShipment.php:204-217`:

> Carriers to offer: those the account has a contract for, **narrowed to those this module has
> settings for**. A carrier the account has but we have no config path for cannot be offered — it
> would have no fee, no active flag and no drop-off days.

```php
$configured = array_keys(Config::CARRIERS_XML_PATH_MAP);
return array_values(array_intersect($configured, $this->getCapabilities()->carriers()));
```

`CapabilitySet::carriers()` already returns the account's real carriers. The module intersects them
away for one reason: a discovered carrier has no config path. Fix that and the intersection goes.

Three things must be true when this is done.

1. A carrier the API newly returns appears in the settings **switched off**, and never reaches the
   checkout until the merchant enables it. Same for a new option on an enabled carrier.
2. Order mode (PPS) follows the account, per scope.
3. `PackageRepository` is gone.

## Decisions

| Question | Decision |
|---|---|
| Generated settings | Render time, per scope. No file. |
| Defaults | A module `ConfigSourceInterface` at **sortOrder 5 — below `modular`** — with `etc/config.xml` kept as the grandfather layer. |
| Existing merchants | Protected by merge order, not by a transcribed table. No data migration for activation. |
| New carriers and options | Off until switched on, both. |
| Carrier path naming | **`ups` is renamed to `upsstandard`**, so the path is purely derived with no exceptions. |
| Option names | Derived from the wire name. `V2_NAMES_MAP` shrinks to a **6-entry legacy alias table**; `TO_CHECK` is deleted. |
| Reverse names | Not derived — **remembered**. The V2 name is already carried on `CarrierCapability` and persisted in the account row. |
| Carriers the SDK cannot export | Shown, but **off and not enablable**, gated on `CarrierApiMapping::isValid()`. |
| `requires` | **Enforced at export** — a missing companion is added, single level, and logged. |
| `excludes` | Resolved by the doc's provenance ranking, in this plan. Equal tiers are not arbitrated. |
| Delivery | **Six stacked PRs**, each based on the last, so no diff is large. |
| Fees | **Opt-in.** Only 3 of 14 options carry a checkout fee today, so a new option arrives as a bare toggle. |
| Order mode ('order v1') | The `print/export_mode` setting is **deleted**. Export mode derives 100% from the account. |
| Delivery titles | Generated per capability name, not per carrier. Neutral default empty, so the widget's own label wins. |
| Proposition | Resolved per store through the existing `AccountPlatform`, not the `Config::PLATFORM` const. |
| `PackageRepository` | Deleted, replaced by two stateless services. Inside this ticket, own commits. |

## Why sortOrder 5 and not 50

`etc/config.xml` is still load-bearing. `dynamic_settings.json` carries **no `default` key on any of
its 263 fields** — it describes the form only. Values resolve through `ScopeConfigInterface::getValue()`,
whose fallback chain ends at `config.xml`. That is why `<carrier>/delivery/active` answers `'1'` for
all eight carriers with no database row.

`ConfigSourceAggregated::get()` (`vendor/magento/module-config/etc/di.xml:183-200`) merges ascending
with `array_replace_recursive`, so **later wins**:

| sortOrder | source | holds |
|---|---|---|
| **5** | **ours** | **generated neutral defaults** |
| 10 | `ModularConfigSource` | every module's `config.xml` |
| 100 | `RuntimeConfigSource` | `core_config_data` rows |
| 1000 | `systemConfigInitialDataProvider` | `env.php` |

Sitting **below** `config.xml` rather than above it makes the hard requirement true by construction:

- `postnl/delivery/active` — we answer `0`, `config.xml` answers `1` → **1**. No live merchant loses
  a carrier, with no risk of a transcription slip across 208 paths.
- A carrier the API has just started returning has no `config.xml` entry → our `0` stands.
- A new option on an existing carrier → same.

So `etc/config.xml` **is** the grandfather record, diffable against git history, and there is no
`UpgradeData` migration for activation at all. Give the file a header comment saying so, and that
new settings go in the catalogue, not here.

### The source emits the `default` scope only — and per-scope control is untouched

To be clear about what this does **not** restrict: turning a carrier off in one scope keeps working
exactly as today. A merchant saves a row at that `(scope, scope_id)`, it lands at sortOrder 100, and
it beats every default. Nothing here narrows per-scope configuration.

What is not per scope is the **neutral default**, for two reasons.

**It is the same answer at every scope.** The neutral for `<carrier>/delivery/active` is `0`
whichever scope asks. Two scopes on different accounts do not disagree about it: scope A having GLS
and scope B not does not make GLS's default differ — it is `0` for both. What differs per scope is
*which fields render*, and that is the form, which **is** per scope.

**Emitting it per scope would break the grandfathering.**
`Magento\Store\Model\Config\Processor\Fallback::prepareStoresConfig()` is literally
`array_replace_recursive($defaultConfig, $websiteConfig, $storeConfig)`
(`vendor/magento/module-store/Model/Config/Processor/Fallback.php:162`), so store beats website
beats default. `config.xml` has only a `<default>` block, so a store-scope `0` from our source would
have nothing to compete with at store level and would win the cascade — switching a live merchant's
carrier off at every store. Default-scope-only is what keeps per-scope safe.

So the source needs no `ScopeCodeResolver`, no `StoreManagerInterface` and no
`Config::getScopeCoordinates()`.

Proof the mechanism already works here: `myparcelnl_magento_postnl_settings/package_small/active` is
the only `*/active` field in the JSON with no `config.xml` default. It resolves to `''`, and
`Config::getBoolConfig()` does a strict `'1' ===`, so it is off.

## Naming a carrier the module has never heard of

`Carrier::V2_NAMES_MAP` (`src/Model/Shipment/Carrier.php:42-52`) is written by hand, but the reverse
direction is a rule. `strtolower(str_replace('_', '', $v2Name))` reproduces **all eight** module
names exactly, and also the SDK's other five verbatim (`BPOST → bpost`,
`POSTE_ITALIANE → posteitaliane`, `UPS_EXPRESS_SAVER → upsexpresssaver`). The convention is the
API's, not a coincidence.

**Renaming `ups` to `upsstandard` removes the last exception**, which is what makes the derivation
safe to rely on:

| needs | comes from |
|---|---|
| module name | `strtolower(str_replace('_', '', $v2Name))` |
| config path prefix | `myparcelnl_magento_{name}_settings/` — no aliases, no map |
| label | `Carrier::humanFor()` already returns the name back for a carrier the SDK does not know (`:73-80`) |
| defaults | the config source, answering `active = 0` |

`toV2Name()` stays a map for outbound requests; a discovered carrier carries its own V2 name, so
nothing reverses the derivation.

`Config::getCarrierConfig()` (`src/Service/Config.php:202-211`) stops returning `null` for an
unknown carrier and derives instead. `Config::CARRIERS_XML_PATH_MAP` becomes a derived lookup.
`InsuranceAmountSetting::carrierFor()` (`src/Model/Settings/InsuranceAmountSetting.php:29-46`) loses
its special case — it already documents the `ups` alias at `:37-38`.
`Tests/Unit/Model/Shipment/V2NameMapTest.php:100` currently pins `V2_NAMES_MAP`'s keys to the map's;
rewrite it to pin the **round trip** instead.

### The derivation is one-way, and that matters

`MY_CARRIER → mycarrier` is **lossy**. `strtolower(str_replace('_', ''))` destroys the word
boundaries, so `mycarrier` cannot be turned back into `MY_CARRIER` — it could equally have been
`MYCARRIER`. Real cases: `dhlforyou` could be `DHL_FOR_YOU`, `DHLFORYOU` or `DHL_FORYOU`;
`upsstandard` could be `UPS_STANDARD` or `UPSSTANDARD`. Only the single-word names round-trip.

**The reverse does not need deriving — it is already remembered, twice:**

- **In memory.** `CarrierCapability` already stores both: `$carrier` (the module name via
  `fromV2Name()`) and `$v2Carrier`, the raw wire name (`:20`, `:44`, `:73-74`). There is just no
  accessor — `$v2Carrier` is read only inside `unknownValues()` at `:141`. Add `v2Carrier()` and
  `CapabilitySet::v2NameFor(string $carrier): ?string`.
- **Persisted.** `Importer::createArray()` stores `contract_definitions` verbatim, and every item
  names its carrier in V2 form. So the reverse map is already in the
  `account_settings_<fingerprint>` row and is readable per scope through
  `ContractDefinitions::forScope()` with no API call.

So `Carrier::toV2Name()` becomes: the legacy map first, then the stored capability answer.

### Is the map in the SDK? Only the half we do not need

`MyParcelNL\Sdk\Model\Shipment\Mapping\CarrierApiMapping` maps **V2 enum name → v1 integer carrier
id** (`RefTypesCarrierV2::DHL_FOR_YOU → RefTypesCarrier::DHL_FOR_YOU`). It does not know the
module's lowercase name at all — `docs/sdk-v11.md:79-80` already records that `V2_NAMES_MAP` "is the
module's own". And it is a hardcoded list of 14.

### The real ceiling: a new carrier cannot be exported

`OrderShipmentOptions::carrierId()` (`src/Model/Shipment/OrderShipmentOptions.php:76-88`):

```php
$v2Name = Carrier::toV2Name($carrier);        // the module's reverse map
if (null === $v2Name) { throw new RuntimeException('carrier "%s" is not one this module knows'); }
return SdkCarrier::toId($v2Name);             // the SDK's 14-entry list
```

Shipment create needs the **v1 integer id**, and only the SDK knows it. A carrier the installed SDK
does not list therefore **cannot be exported**, whatever this module does. That bounds the feature
honestly:

| surface | new carrier works? |
|---|---|
| settings form | yes — derived name, derived path, label from `humanFor()` |
| checkout | yes, once the merchant enables it |
| **export** | **no** — blocked until the SDK ships the carrier's id |

**So the generator needs a gate.** Render a discovered carrier, but when `CarrierApiMapping::isValid($v2Name)`
is false, keep it switched off and **not enablable**, with a note that the carrier needs a module
update. Without the gate a merchant enables it, takes orders, and every export throws. This is the
one place the "new carriers just work" promise has a hard limit, and it belongs in the release notes.

### The rename is a data migration

Every existing merchant has `core_config_data` rows under `myparcelnl_magento_ups_settings/`. A
migration in `src/Setup/Migrations/` must rewrite them:

```sql
UPDATE core_config_data
   SET path = REPLACE(path, 'myparcelnl_magento_ups_settings/', 'myparcelnl_magento_upsstandard_settings/')
 WHERE path LIKE 'myparcelnl_magento_ups_settings/%'
```

Sweep `grep -rn "ups_settings" src/ etc/ view/ Controller/ Tests/ i18n/` first — `etc/di.xml`
carries carrier-specific insurance virtual types that may name the path. `i18n/*.csv` keys on the
label string, not the path, so it needs nothing. Note `UpgradeData::upgrade()` only runs when
`setup_version > data_version`, so bump `etc/module.xml`.

## No option names in the module — how far that can go

The goal is right: the module should stop knowing *which* options exist, so a new one costs nothing.
It holds almost all the way, and the exceptions are worth stating because they are small and each
has a reason.

**The derivation.** Strip a leading `requires`, then camelCase → snake_case. Against the 14 entries
in `ShipmentOption::V2_NAMES_MAP`, **8 derive and 6 do not**:

| derives | needs an alias |
|---|---|
| `hideSender`, `insurance`, `sameDayDelivery`, `requiresSignature`, `requiresReceiptCode`, `priorityDelivery`, `freshFood`, `frozen` | `requiresAgeVerification → age_check`, `oversizedPackage → large_format`, `recipientOnlyDelivery → only_recipient`, `printReturnLabelAtDropOff → printerless_return`, `returnOnFirstFailedDelivery → return`, `scheduledCollection → collect` |

Those six cannot be renamed: `docs/sdk-v11.md:77` records that `core_config_data`, the order's
delivery-options JSON and the checkout widget all speak module snake_case, and that it is
*"Persisted data; cannot change without a migration"*. So `V2_NAMES_MAP` shrinks from a 14-entry
dictionary to a **6-entry legacy alias table**, exactly like the carrier one. Everything else
derives, in both directions.

**`no_tracking` proves it.** `noTracking → no_tracking` derives cleanly, and neither `tracked` nor
`no_tracking` appears anywhere in the module today. So `tracked` disappearing costs nothing, and the
new option needs no constant, no map entry and no JSON — only the "no fee" row below.

**Three things the module must still name**, and all three are already documented as module-owned
rather than capability-derived:

1. **Behavioural rules the API does not state.**
   - `ShipmentOption::LIMIT_PACKAGE_TYPE = [AGE_CHECK]` (`:66`) — the compliance rule from
     `docs/sdk-v11.md:62-66`: a mailbox order loses the mailbox, not the age check.
   - `ShipmentOption::LIMIT_DELIVERY_TYPE = [RECEIPT_CODE => [STANDARD]]` (`:78`) — its docblock
     already says "Not a capabilities fact … this rule is the module's own".
   - **New: `no_tracking` carries no fee.** Capabilities does not say "this option may not have a
     surcharge"; the ticket does.
2. **Options with bespoke UI.** `insurance` is not a toggle+fee — it is `frontend_model:
   InsuranceAmount` with bounds from the contract. `large_format` uses `Model\Source\LargeFormatOptions`
   and its `from_price` depends on the value `"price"`, not `"1"`.
3. **Nothing else.** `ShipmentOption::TO_CHECK` (`:38-48`) — "the subset the admin New Shipment form
   asks the carrier about" — is precisely the "module knows which options exist" list. **Delete it**
   and ask about whatever `optionsFor()` returns.

So the catalogue's rule is: **an option I know nothing about gets a bare toggle, derived from its
wire name.** Fees, from-prices and bespoke widgets are opt-in lists, each row carrying its reason.

### The honest version of "no option names in the code"

The names do not all disappear. Counted, the module still names ten options across five lists:

| list | names | why it cannot come from the API |
|---|---|---|
| legacy aliases | `age_check`, `large_format`, `only_recipient`, `printerless_return`, `return`, `collect` | persisted config paths and order data |
| may carry a fee | `signature`, `only_recipient`, `receipt_code` | a pricing decision, not a capability |
| no from-price | `age_check`, `hide_sender` | not merchant-automatable from order value |
| bespoke UI | `insurance`, `large_format` | contract bounds; a non-boolean source model |
| behavioural limits | `age_check`, `receipt_code` | compliance and delivery-type rules the API does not state |

The property that actually delivers the goal is not "no names" but **"no new names ever needed"**.
Every list above is closed over the options that already exist. A new option joins none of them: it
derives its name, gets a toggle, no fee, and renders wherever capabilities says it applies.
`no_tracking` is the proof — it needs not one line in any of those five lists.

## `requires` and `excludes`

`docs/design/capability-option-dependencies.md` records that the data *"arrives, is parsed, and is
discarded"*: every option in a capabilities result carries `requires` and `excludes` in v2 names,
`OptionSet` parses the whole body, and `CapabilitySet::optionValue()` only ever yields the insurance
bounds. The dependency keys sit in the same array, unread.

### `requires` is enforced at export, and that is the point

An age-check order exported without signature, to a carrier whose contract requires signature with
age check, is a rejection the module can see coming. **The export adds the companion rather than
letting the API refuse.**

The seam exists. `ShipmentOptionsResolver::resolve()` (`src/Service/ShipmentOptionsResolver.php:382`)
is the one place a shipment's options are decided — *"Decides what shipment options one shipment
gets, from the posted options, the configured defaults, the country, the carrier and per-product
attributes"* — and `insuranceRange()` (`:135-160`) already makes exactly the call needed:

```php
$this->objectManager->get(ShapeLookup::class)->forShape(
    (int) $this->order->getStoreId(), $this->cc, $packageType, $this->carrier
);
```

The real shape, not a union — its own comment already explains why a package-type-agnostic answer is
wrong here. Hoist that lookup so the insurance clamp and the dependency pass share it rather than
asking twice.

Then, in `resolve()`, after the options are decided and before the `ShipmentOptions` is returned:

1. For every option that is **on**, read `requiresFor($carrier, $packageType, $option)`.
2. Any required companion that is **off** is switched on. **Single level only** — do not follow the
   companion's own `requires`. `docs/design/capability-option-dependencies.md:39-47` gives the
   reason: PostNL's receipt code requires insurance, insurance requires signature and only
   recipient, and receipt code *excludes* both. One more step enables the two options receipt code
   exists to exclude. The PDK recurses and then undoes the damage, and its correctness rests on
   statement order inside one method.
3. **Log every option added**, naming the order and what required it. This is not bookkeeping: the
   merchant may be shipping an option the customer was never offered and did not pay for. Adding it
   is still the right trade — the alternative is a failed export — but it must be visible.
4. A permissive set changes nothing.

Single level does not guarantee a valid shipment; the chain above can still end somewhere the API
refuses. That is correct and deliberate — `docs/sdk-v11.md:40` already rules it: **the API is the
validator.** What this removes is the *avoidable* rejection.

### `excludes` uses the provenance ranking, in this plan

The doc gets no separate ticket, so its ranking lands here.

Blanket forcing is wrong, and `docs/design/capability-option-dependencies.md:49-56` says why: the
PDK's winner in a mutual exclusion is whichever business-logic definition sorts first
alphabetically, so `age_check` beats `receipt_code` **by luck**, and renaming a class flips it.
Almost every exclusion in the real graph is mutual, so a one-directional rule does nothing at all.

Rank **where the value came from** instead, which needs no list of option names:

| tier | source | why it outranks the next |
|---|---|---|
| 1 | product attribute | 18+ is a legal fact about the goods, not a preference |
| 2 | posted for this shipment | the operator's decision about this order |
| 3 | the checkout's stored delivery options | the customer chose it, and may have paid for it |
| 4 | configuration default | a standing preference, not a decision about this order |

The higher tier wins. **On equal tier the module does not arbitrate**: both stay as chosen and the
API refuses, naming the order. Dropping one would silently lose an age check an 18+ order needs,
which `docs/sdk-v11.md:62-66` rules out.

The tiers are already separate constructor arguments on `ShipmentOptionsResolver` — the product
attributes, `array $options` (tier 2), `DeliveryOptions $deliveryOptions` (tier 3) and
`DefaultOptions $defaultOptions` (tier 4). Provenance is recoverable without restructuring. What
collapses it is one method:

```php
private function optionIsEnabled($optionKey): bool
{
    return (bool) ($this->options[$optionKey] ?? $this->defaultOptions->hasOptionSet($optionKey, $this->carrier));
}
```

Give it a sibling that answers **which tier** decided, and the ranking is a comparison. Tier 1 has
one member today; it earns its place as a rule about provenance rather than a named exception, so a
future product-driven flag joins it with no list to maintain.

### Two supporting pieces here

1. **Expose the data.** `OptionSet` and `CapabilitySet` gain
   `requiresFor($carrier, $packageType, $option): string[]` and `excludesFor(...)`, returning
   **module names** through `ShipmentOption::fromV2Name()`, an unknown name dropped and logged
   rather than substituted — the rule `docs/sdk-v11.md:82-83` already sets.
2. **Warn at save time too.** A merchant who auto-forces two mutually excluding options in
   `default_options` has a standing misconfiguration, and finding out at export is late.
   `ConfigChange::rejectionFor()` (`src/Observer/ConfigChange.php:176-191`) is the seam, validators
   registered in `etc/di.xml:81-87` — only `InsuranceAmount` today. It rejects the one field, never
   the form, and returns early on a permissive set.

**Not** in the `delivery` group: `signature_active` means "offer signature at checkout", not "apply
it", so offering both signature and receipt code is correct — the customer picks one and the
exclusion applies per shipment. And **not** through `depends`: that mechanism is AND-combined
`{field, value}` visibility evaluated by `checkDependencies()`, and mutual exclusion is a negation it
cannot express.

Two facts from the doc bear on the generator directly: **pairings differ per carrier**, so they are
read per carrier and never assumed, which is the shape the generator already has; and **a permissive
set forces nothing**.

## Delivery titles: capability-driven, not per carrier

`delivery_titles` is 24 fields in the general section. They can be automated, and mostly should be —
but per **capability name**, not per carrier.

The reason per-carrier is the wrong axis: 24 titles x N carriers is ~190 fields, and they only
differ when two carriers offer the same option and the merchant wants different wording. Rare, and
it can be added later as an override layer (`<carrier>_settings/delivery_titles/<name>` read with a
fallback to the general one) without changing anything decided here.

The reason automation is cheap: **these are overrides, not labels.** The widget
(`@myparcel-dev/delivery-options` v7) ships its own strings, and
`Checkout::getDeliveryOptionsStrings()` already falls back with `?: __('…')`. So a new option needs
no title field at all — leave the generated one empty and the widget labels it.

So: generate one `{name}_title` per delivery type, package type and option the account actually has,
neutral default empty. The field count collapses to what is in use, a new option gets a title field
for free, and `no_tracking` needs nothing special.

## `no_tracking` end to end: half free, half real

The settings half is easy, and easier than expected:

- Neither `tracked` nor `no_tracking` appears anywhere in this module today, so `tracked`
  disappearing from capabilities costs nothing.
- The capabilities V2 name is `noTracking`, which **derives** to `no_tracking`.
- The SDK already carries the shipment field: `no_tracking` sits in
  `Client/Generated/CoreApi/Model/RefShipmentShipmentOptions::$attributeMap:250`, next to `tracked`
  at `:258`. **No SDK release is needed.**

The half that is real: **a newly discovered option does not reach the export today.** Three fixed
key lists stand in the way.

| list | where |
|---|---|
| the 12 options a shipment gets | `ShipmentOptionsResolver::resolve()` (`:415-433`) |
| the persisted key list | `Adapter\DeliveryOptions\ShipmentOptions::KEYS` (`:28`) |
| each named constructor | `ShipmentOptions` (`:71-95`) |

`docs/sdk-v11.md:183-184` records that the key order there is a persisted format, so **append, never
reorder**. `resolve()` keeps explicit answers for the options with bespoke rules — `hasSignature()`
carries a BE/only-recipient rule, `getInsurance()` clamps — and gains a **generic pass** over every
other option capabilities reports for the shape, answered by `optionIsEnabled()`. `no_tracking`
takes the generic path, and so does the next option after it.

That generic pass is the same machinery the dependency enforcement needs, so the two share a PR.

## Switching proposition

`Config::PLATFORM = 'myparcel'` (`src/Service/Config.php:28`) is a const, and
`Checkout::getGeneralData()` hands it to the widget as `'platform'` (`src/Model/Quote/Checkout.php:133`).
That is the only thing standing between the module and the ticket's "switchen van propositie".

The seam already exists. `src/Service/TrackTrace/AccountPlatform.php` answers
`forStore(?int $storeId): ?int` — the proposition id behind that store's API key, read from the
stored account row, memoised per request, never throwing. Track & trace already uses it to pick a
host.

Change `Checkout.php:133` to resolve through `AccountPlatform` and fall back to `Config::PLATFORM`
when it answers null. The widget wants a platform **name** where `AccountPlatform` gives an **id**,
so add the id → name map next to it — check whether the SDK already ships one before writing it.

## Discovery: which call enumerates carriers

Not contract definitions. `Client::sendContractDefinitions()`
(`src/Model/Shipment/Capabilities/Client.php:116-126`) posts `{"carrier": "<V2NAME>"}` — the carrier
is **required**. `Importer::fetchContractDefinitions()` therefore loops
`Config::CARRIERS_XML_PATH_MAP` and can only rediscover the eight.

The broad capabilities call does enumerate: `ShapeLookup::forShape($storeId, $country)` with a null
package type and null carrier returns one `CarrierCapability` per carrier the account holds. It
needs a **country**, which the settings screen has not got.

So discovery and detail are two calls, both in `Importer`, which already runs only on an API key
change and on the import button:

1. **Discover** — the broad capabilities call for `general/country/default` (the fallback
   `Checkout.php:95` already uses), to learn the carrier list.
2. **Detail** — contract definitions per discovered carrier: no country, no shipment.

## The three layers, and which may call HTTP

| layer | runs | HTTP | reads |
|---|---|---|---|
| `Importer` | API key change, import button | **yes** | the API |
| settings generator | admin form render | no | `ContractDefinitions::forScope()` |
| config source | config tree build | **never** | `core_config_data` directly |

The config source may not call `scopeConfig`, because it runs while that tree is being built. It
reads rows the way `Settings::hasRowAtScope()` and `Maintenance::configRows()` already do, through
`Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory`.

## What is generated

The 263 fields are a small set of shapes repeated across carriers:

| share | fields | decided by |
|---|---|---|
| 20% | 54 | capabilities — the `*_active` toggles, 22 distinct ids |
| 31% | 84 | merchant logistics — `drop_off_days`, 7 days x (active + cut-off) |
| 28% | 75 | module templates — fees, from-prices, weights |
| 19% | 50 | the general section |

Capabilities decide three things, each pulling its companion fields along: which carrier sections
exist (`carriers()`), which groups a carrier gets (`packageTypesFor()` → `mailbox`,
`digital_stamp`, `package_small`; `deliveryTypesFor()` → `morning`, `evening`, `pickup`), and which
option pairs appear inside `delivery` and `default_options` (`optionsFor()`). A carrier with no
`MAILBOX` loses the whole `mailbox` group, not just its toggle.

**Labels and tooltips must be copied verbatim** — they are the `__()` msgids in `i18n/*.csv`
(e.g. `Automate 'Signature on receipt'` at `i18n/nl_NL.csv:62`). Changing a string silently drops a
translation in six locales.

`monday` and `saturday` are not capabilities — `Checkout.php:210` already documents monday as
configuration-only. They stay merchant-only fields.

## The stack

Six pull requests, each based on the one before it, so every diff stays readable. Base of the stack
is `feat/use-sdk-v11-shipments` once its PR is open — and **after INT-1286**, which lands first.

```
main
 └─ feat/use-sdk-v11-shipments            (open PR, this stack's base)
     └─ 1  refactor: stateless package type services
         └─ 2  refactor: derive carrier and option names
             └─ 3  feat: honour capability option dependencies
                 └─ 4  feat: generate the settings form from capabilities
                     └─ 5  feat: supply settings defaults from capabilities
                         └─ 6  feat: derive export mode and proposition from the account
```

| # | PR | why it sits here | steps below |
|---|---|---|---|
| 1 | stateless package type services | Largest deletion, touches only `Checkout`'s package-type path, independent of the rest. Rebasing a deletion over later work costs more than the reverse. **Carries the `DivisionByZeroError` fix as its first commit**, so the crash is gone whatever happens to the rest of the stack. | 12 |
| 2 | derive carrier and option names | Every later PR needs `fromV2Name()` and derived paths. Carries the `ups → upsstandard` rename and its migration. No visible behaviour change. | 2, 3 |
| 3 | honour capability option dependencies | Needs PR 2's derivation. Opens the three fixed option key lists, which is also what lets `no_tracking` reach the wire. | 10 |
| 4 | generate the settings form | Needs PR 2's paths and PR 3's open key lists. `no_tracking` becomes visible here, so the ticket's acceptance is verifiable at this point. | 4, 5, 6, 8 |
| 5 | supply settings defaults | Needs PR 4's `Generator::neutralDefaults()`. Starts with the spike; carries the `config.xml` pruning and the orphan triage. | 1, 7 |
| 6 | account-derived export mode and proposition | Needs PR 4's `AccountFlags`. Carries the one intended behaviour change and the cron partition, so it can be reverted alone. | 9, 11 |

Two rules. **Rebase the whole stack when a lower PR changes**, rather than merging downward, so each
diff keeps showing only its own work. And **land nothing below a failing PR** — each must be green
on `vendor/bin/pest` and on `setup:di:compile` by itself.

If review wants fewer branches: 2 and 3 merge cleanly, and 5 can fold into 4. Do not fold 1 into
anything, and keep 6 separate whatever else happens, because of the behaviour change.

## Implementation

The steps below are numbered independently of the stack; the table above maps them to branches.

### 1. Spike the config source seam
`private/config-source-probe.php`, following the `private/caps-cache-dump.php` bootstrap pattern.
A throwaway `ConfigSourceInterface` returning two hard-coded keys, wired at sortOrder 5, then:

```bash
php -dmemory_limit=-1 bin/magento setup:di:compile \
&& php -dmemory_limit=-1 bin/magento cache:flush \
&& php -dmemory_limit=-1 bin/magento dev:di:info systemConfigSourceAggregated \
&& php -dmemory_limit=-1 bin/magento config:show myparcelnl_magento_general/spike/probe \
&& php -dmemory_limit=-1 bin/magento config:show myparcelnl_magento_postnl_settings/delivery/active
```

Four assertions: compile does not error; `dev:di:info` shows **four** `sources` entries, not three
(three means our item replaced the array rather than merging into it); `spike/probe` prints
`reached`; and `postnl_settings/delivery/active` prints **`1`**, not `0`. That last line is the
grandfather proof and the single most important result of the spike.

The merge mechanism is already proven in-module: `etc/di.xml:4-13` redeclares
`Magento\Sales\Model\ResourceModel\Order\Grid` — a virtualType owned by `Magento_Sales` — and merges
four items into its `columns` array. What the spike adds is the **ordering** proof.

Then `cache:flush`, repeat, and check `var/log/*.log` for recursion. Revert the spike before step 2.

If assertion 2 fails: declare our own aggregated virtualType and override the `source` argument on
`Magento\Config\App\Config\Type\System\Reader`, listing all four sources. Cost: a future Magento
source addition is silently dropped, so document it. If assertion 4 fails: the ordering assumption
is wrong — move to sortOrder 20, delete `config.xml`'s `myparcelnl_*` blocks, and transcribe a
legacy const pinned by a fixture. Everything downstream is unchanged either way.

### 2. Rename `ups` to `upsstandard`
Config constant, `etc/config.xml` block, any `etc/di.xml` insurance virtual type, and the
`core_config_data` migration above. Its own commit, so the rename is reviewable alone.

### 3. Derive carrier and option names
`Carrier::fromV2Name()` falls back to the derivation; `Config::CARRIERS_XML_PATH_MAP` becomes a
derived lookup; `getCarrierConfig()` stops answering `null`; `InsuranceAmountSetting::carrierFor()`
drops its special case; `V2NameMapTest` re-pinned to the round trip.

Same for options: `ShipmentOption::fromV2Name()` strips a leading `requires` and snake-cases the
rest, with the 6-entry legacy alias table for the ones that cannot derive. Delete
`ShipmentOption::TO_CHECK` and have `NewShipment` ask about whatever `optionsFor()` returns. Keep
`LIMIT_PACKAGE_TYPE` and `LIMIT_DELIVERY_TYPE` — they are module rules, not capability facts, and
their docblocks already say so.

### 4. The field-template catalogue
New `src/Model/Settings/Blueprint/` — `Field`, `Group`, `Section`, `Blueprint`, `Catalogue`.
`Field::toArray()` emits exactly the keys `dynamic_settings.phtml` and `Settings` already read, so
the template needs no change. `Field::neutral()` is a **separate channel** to the config source and
is never serialised into the form array.

Neutral defaults: `*_active` → `'0'`, `*_fee` → `'0'`, `*_from_price` → `'1'`,
`large_format_active` → `'No'`, `mailbox`/`package_small` weight → `'2000'`,
`drop_off_days/cutoff_time_N` → `'15,30,00'`, insurance → `'0'`, `*_title` → `''`.

### Money fields are opt-in, not opt-out

Counted across every carrier section, **only three shipment options carry a checkout fee today**:

| group | field | options that have it |
|---|---|---|
| `delivery` | `{opt}_fee` | `signature`, `only_recipient`, `receipt_code` — and the merchant-only `monday`, `saturday` |
| `default_options` | `{opt}_from_price` | all except `age_check` and `hide_sender` |
| delivery-type groups | `fee` | `morning`, `evening`, `pickup`, and `mailbox/priority_delivery` |
| package-type groups | — | none. `mailbox/fee` exists only as an orphan `config.xml` default. |

So `no_tracking` is not the exception — **it is the majority.** `age_check`, `hide_sender`,
`large_format`, `return`, `collect`, `printerless_return`, `same_day_delivery`, `fresh_food` and
`frozen` all have no checkout fee either.

That inverts the rule. Generating a fee for every option and denying `no_tracking` would add
surcharge fields to nine options that never had one — a UI change nobody asked for, and a pricing
decision capabilities cannot make. Instead:

**A generated option gets a toggle. It gets a fee only if it is on the fee list, and a from-price
only if it is not on the from-price exclusion list.** A newly discovered option therefore arrives as
a bare toggle, which is the safe shape.

`no_tracking` then needs **no exception row at all** — it gets no fee because nothing does unless
listed. Its neutral `'0'` is the acceptance criterion: tracking is on wherever available, and
`no_tracking` is an explicit opt-out. It renders only under the package types
`optionsFor($carrier, $packageType)` reports it for, which is the first acceptance line for free.

The remaining bespoke rows are two: `insurance` emits its contract-bounded block instead of a toggle
pair, and `large_format` uses `LargeFormatOptions` with `from_price` depending on `"price"`.

Every `depends` entry is built from the `Field` object it points at, never a hand-written path
string, so a dangling dependency is impossible by construction.

`drop_off_days` is emitted for **every** generated carrier. GLS and Trunkrs lack it today while
`Checkout::getDropOffDays()` reads those exact paths for every carrier — a live gap that generating
the group closes. The paths already read as `0`, so nothing changes until a merchant sets them.

### 5. The generator
`src/Model/Settings/Blueprint/Generator.php` — pure, no Magento:
`for(AccountFacts $facts, array $retiredPaths = []): Blueprint`.

Carriers come from `capabilities()->carriers()`, **not** intersected with a static list — that
intersection is the bug. When `isPermissive()`, emit the full catalogue for the known eight; that is
the rule `DeliveryCostsMatrix::getCarriers()` already documents, and that block should delegate here
so there is one answer.

**The retired group matters and is requirement 1's inverse.** `Checkout::getActiveCarriers()`
(`src/Model/Quote/Checkout.php:298-313`) is pure config, so a carrier that *disappears* from the
contract keeps its grandfathered `delivery/active = 1` and keeps reaching the checkout. Without a
retired group there is no UI left to switch it off. One field per stored path the blueprint no
longer generates, labelled *(no longer in your contract)*.

`src/Model/Settings/Blueprint/ScopeBlueprints.php` is the Magento-side adapter:
`forScope(string $scopeName, ?int $scopeId): Blueprint`, memoised, reading
`ContractDefinitions::forScope()` — the stored definitions, so no HTTP and no 60-second
negative-cache window in the admin.

New `src/Service/AccountSettings/AccountFlags.php` reads the `account.general_settings` block per
scope. It takes **`ScopeConfigInterface` + `Fingerprint` only**, never `Service\Config`, because
`Config` will depend on it in step 8 and the reverse edge must not exist. `AccountSettings`
(`src/Model/Settings/AccountSettings.php`) reaches for `ObjectManager` in its constructor, so share
the row parser with it, not the class.

### 6. `Service\Settings` becomes scope-aware
Keep the public API, replace the body. Drop `ModuleDirReader`, `Json`, `$settingsCache`,
`loadSettingsFromFile()`. `getSections()` and `getAllFieldPaths()` take `($scopeName, $scopeId)`.
The row helpers (`hasRowAtScope`, `storedValuesAtScope`, `hasOwnValue`,
`getCurrentScopeFromRequest`) are unchanged — they are about `core_config_data`, not the file. Drop
the `TODO (INT-1289)`.

`DynamicSettings::getSections()` passes its existing `getCurrentScope()`. Add `isPermissive()` for
a banner mirroring `view/adminhtml/templates/new_shipment.phtml:25-32`.

Delete the dead render path while the template is open: `DynamicSettings::getSaveUrl()` (`:150-153`)
and `view/adminhtml/layout/myparcel_settings_index.xml` both point at controllers that do not exist,
and the `<form>` in the phtml is nested inside Magento's own config form, so browsers discard it.

Then delete `etc/dynamic_settings.json`.

### 7. The config source
`src/App/Config/Source/GeneratedDefaults.php`. **The constructor list is the contract** — nothing
may be added without re-running the recursion check:

```php
__construct(
    DeploymentConfig $deploymentConfig,
    Config\Data\CollectionFactory $collectionFactory,
    Scope\Converter $converter,
    Generator $generator,
    Fingerprint $fingerprint
)
```

No `ScopeConfigInterface`, no `Service\Config`, no `StoreManagerInterface`, no HTTP client. Guard on
`isDbAvailable()`. Two queries: all `api/key` rows → fingerprints; all matching
`account_settings_<fingerprint>` rows at default scope. Union the `contract_definitions`, build a
`CapabilitySet`, generate, flatten to `path => value`, nest with `Converter`, wrap as
`['default' => …]`, and return `(new DataObject($tree))->getData($path) ?? null` verbatim from
`RuntimeConfigSource`. Wrap it all in `try/catch (Throwable)` → log and return `[]`; a source that
throws takes the whole config build down.

### 8. `ConfigChange` save allow-list
`src/Observer/ConfigChange.php:87` becomes `getAllFieldPaths($scope, $scopeId)` — both are already
resolved two lines above. A field not offered at this scope must not be writable at this scope.

Constrain retired paths or the gate widens from 263 known paths to "any path with a row": a path
qualifies only if it starts with `XML_PATH_GENERAL` or a carrier prefix, **and** does not start with
`XML_PATH_ACCOUNT_SETTINGS`, **and** is not the API-access-token hash path. Both exclusions live
under `myparcelnl_magento_general/`, so a crafted POST could otherwise overwrite the stored account
row or a token hash.

`ConfigChange` skips unknown paths silently at `:98`. Make it log the skip, or a merchant loses a
value with no message when capabilities move between render and save.

### 9. Order mode derived entirely from the account
The merchant setting goes.

- Delete `print/export_mode` from the catalogue and `src/Model/Source/ExportMode.php`.
- `Config::getExportMode(?int $storeId = null): string` returns
  `AccountFlags::hasOrderMode(SCOPE_STORES, $storeId) ? EXPORT_MODE_PPS : EXPORT_MODE_SHIPMENTS`.
  This also fixes the ambient-store bug at `src/Service/Config.php:237`.
- The migration deletes the now-dead `myparcelnl_magento_general/print/export_mode` rows.
- Five of six callers have a store id in hand: `Observer\NewShipment.php:154`,
  `CreateConceptAfterInvoice.php:102`, `Block\Sales\NewShipment.php:407`,
  `Ui/.../TrackActions.php:67`, `Controller/.../CreateAndPrintMyParcelTrack.php:59`.
- `src/Cron/UpdateStatus.php:91` is the one place that does not. **The cron already resolves the API
  key from each order's own store and never invents one**: the order collection selects `store_id`
  explicitly (`:274`), and `incrementIdsByApiKey()` (`:490-497`) passes
  `(int) $orderRow['store_id']` to `ShipmentApiProvider::apiKeyForStoreOrNull()`, whose docblock
  states the rule — *"A store without a key is skipped, never lent another store's key."*

  The export-mode branch breaks that pattern by asking **once, at the top of `execute()`, before any
  order row is loaded**, so it falls back to `Config`'s ambient store — the admin store in a cron.
  The fix is to copy the pattern that is already there: partition the rows by
  `getExportMode((int) $row['store_id'])` and run each branch over its own partition.

  One trap to avoid: `Config::getConfigValue()` does `$storeId ?? $this->storeId`, so passing `null`
  silently falls back to ambient. Always pass an explicit int — every `sales_order` row has a
  `store_id`, and the column is already selected, so there is never a reason to pass null.

  **Own commit**; it is the riskiest change here and should be revertable alone.

### 10. Honour capability option dependencies
`OptionSet` / `CapabilitySet` gain `requiresFor()` and `excludesFor()` returning module names.

Open the three fixed option key lists so a discovered option reaches the wire: `resolve()` keeps its
bespoke answers and gains a generic pass; `ShipmentOptions::KEYS` and the named constructors append,
never reorder. This is what makes `no_tracking` exportable.

`ShipmentOptionsResolver::resolve()` then adds any missing required companion, single level, logging
each addition with the order and what required it. Hoist the `forShape()` call so the insurance clamp
and this pass share one lookup.

`excludes` resolves by provenance: give `optionIsEnabled()` a sibling that reports which tier
decided, rank product attribute > posted > stored checkout choice > configuration default, and leave
equal tiers alone for the API to refuse. A permissive set changes nothing, in either direction.

A validator in `src/Model/Settings/Validator/` joins `InsuranceAmount` in `etc/di.xml:81-87` and
rejects a `default_options` field that forces an option excluded by another already forced on, so a
standing misconfiguration surfaces before the first export rather than after it.

### 11. Proposition per store
`Checkout.php:133` resolves `'platform'` through `AccountPlatform::forStore()` with
`Config::PLATFORM` as the fallback, plus the proposition-id → platform-name map.

### 12. Replace `PackageRepository`
Its own commits, landed **before** the settings work. Detail below.

## "Optioneel per scope!!!!" — how the plan answers it

The ticket's loudest line, with an explicit fallback: if per scope is not possible, block it.
It is possible, and nothing needs blocking. Three layers, three answers:

| layer | per scope? | how |
|---|---|---|
| **the form** | yes | `ScopeBlueprints::forScope()` reads that scope's API key → its account → its contract definitions. Two scopes on different propositions render different carriers. |
| **the values** | yes, already | ordinary `core_config_data` rows at `(scope, scope_id)`. Turning a carrier off in one scope works exactly as today; nothing here narrows it. |
| **the defaults** | no, and deliberately | one default-scope tree of neutral `0`s — the reasoning is under the config source above. |

Only the third looks like a gap, and it is not one: a neutral default is the *same* answer at every
scope, and emitting it per scope would let a store-scope `0` win the `Fallback` cascade over a
grandfathered default-scope `1`.

The bulk-export case in the tester note is already handled upstream: `ShipmentExportService` and
`ShipmentApiProvider` group by API key and split the calls per account (`docs/sdk-v11.md:28-31`), so
orders from stores on different propositions already export against their own account. Pin it with
a test rather than change it.

## Replacing `PackageRepository`

`PackageRepository extends Package extends Config extends AbstractHelper` — a package-type
calculator inheriting a config helper. `@deprecated` at `:34`; `Package::getPackageType()` throws
`'Please remove Package.php and PackageRepository.php and use a service'` (`Package.php:252`). With
no `di.xml` entry it is a **shared singleton with mutable state**, and `Checkout::checkPackageType()`
runs once per active carrier (`Checkout.php:159-160`), each pass overwriting the previous.

Two things are already done, so the rewrite is smaller than the class's reputation suggests:

- **The EAV reads are already extracted.** `src/Service/ProductAttributes.php` is a committed class
  (`50ffb0da`) with `warm()` and `value()`. `PackageRepository::selectPackageType()` calls
  `warmAttributes($products)` first (`:55`, `:518-531`) and reads through `attributes()`
  (`:534`). There is no raw SQL and no `ObjectManager` left in that path, so the new services take
  `ProductAttributes` by constructor and inherit the warmed batch.
- **The memo key is already correct** — `optionForcedOn()` keys on
  `$carrierPath . '|' . $option . '|' . self::productsKey($products)` (`:290-299`), with the product
  set in the key. Its docblock sits in the right place too.

### A fatal bug to fix first

Reproduced, not reported. `getAttributesProductsOptions()` is `?int` and returns `null` when the
product has no EAV row or its catalogue product is gone (`:486-497`). `null` passes both strict
comparisons in `selectPackageType()` and reaches the division:

```php
if (-1 === $mailboxQty)                       { ... }   // null: false
if (0 === $mailboxQty && 0.0 !== $productWeight) { ... } // null: false
if (0 !== $mailboxQty) {                                 // null: TRUE
    $productPercentage = $productQty * 100 / $mailboxQty; // divide by null
```

On PHP 8 that is a `DivisionByZeroError` **in the checkout** — confirmed by running the same branch
structure. `UpgradeData.php:719-729` sets the attribute's `default_value` to `-1`, so a product never
re-saved since that upgrade has no row and hits it. Treat `null` as `-1`, matching the default the
merchant sees in admin. It lands before the characterisation table, so the table can hold a row for
it.

### The replacement

Two services, not one — the deprecation note asks for a split by functionality, and the call sites
split cleanly: two of Checkout's nine touch the package-type decision, seven ask product questions.

| new class | responsibility |
|---|---|
| `src/Service/PackageTypeResolver.php` | Which package type one cart gets from one carrier. `resolve(items, carrierName, country, PackageTypeCandidates, ?storeId): string`. Takes the existing `ProductAttributes` by constructor. |
| `src/Service/CartShippingRules.php` | What the cart's items say about how it may ship: forced options, hidden delivery options, drop-off delay, parcel lockers, priority. |
| `src/Service/PostnlMailboxInternational.php` | The seam that gets `new AccountSettings(...)` out of the decision, so the resolver is testable. |
| `src/Model/Shipment/PackageTypeCandidates.php` | The **input** value object: which package types survive capabilities and the forced options. |

`Resolver`, not `Selector` — the module already uses that suffix for exactly this shape
(`ShipmentOptionsResolver`).

The asymmetry is deliberate and needs a docblock line on each class: `CartShippingRules` takes a
**carrier path**, the resolver takes a **carrier name**. `Checkout::getGeneralData():129` falls back
to `XML_PATH_POSTNL_SETTINGS` when no carrier is active, so those questions get asked for a path
belonging to no carrier. Harmonising them reintroduces a `CARRIERS_XML_PATH_MAP[null]` notice.

**No value object for the answer.** `resolve()` returns a `PackageType::*_NAME` string, because that
string already crosses three boundaries bare: the REST response through `Model\Checkout\PackageType`,
the checkout widget JSON at `Checkout.php:106`, and `DeliveryCosts::getBasePriceForClient()`.

`PackageTypeCandidates` is where `docs/sdk-v11.md` divergence 4 becomes literal: an option the
package type cannot carry narrows the package type; it never drops the option. A mailbox order with
an age check loses the mailbox, not the age check. `isPackageTypeCandidate()` and `canCarry()` stay
in `Checkout` byte-for-byte so `CheckoutCapabilitiesTest` keeps testing the compliance rule at the
same altitude.

**That chain is already correct today — verified, so the rewrite must not disturb it:**

1. `forcedLimitingOptions()` (`PackageRepository.php:262-270`) filters
   `ShipmentOption::LIMIT_PACKAGE_TYPE` — `[AGE_CHECK]` — through `optionForcedOn()`, which reads
   **both** tiers: the product attribute and the carrier's configured default.
2. `isPackageTypeCandidate()` (`Checkout.php:467-490`) first asks
   `hasPackageType($carrier, $packageType)`, then — only when something is forced and the set is not
   permissive — asks the **narrowed** `getCapabilities($country, $packageType)` call.
3. `canCarry()` (`:498-508`) checks `hasOption($carrier, $packageType, 'age_check')` and rules the
   package type out if it answers no.

So an 18+ order on a carrier whose mailbox cannot carry an age check ships as a package, and the age
check survives. Two details worth preserving verbatim: `canCarry()` uses `hasOption()` rather than
`optionsFor()` because the latter has no permissive guard and would read as "supports nothing"; and
`PackageType::PACKAGE_NAME` is deliberately never a candidate — it is the fallback and is never
narrowed. The characterisation table must cover both.

### State that disappears

`storeId` becomes a parameter on every method that reads config, which is what makes the ordering
comment at `Checkout.php:70-71` vanish structurally — there is no order left to get wrong. The
public `$deliveryOptionsDisabled` flag becomes `CartShippingRules::hidesDeliveryOptions()`, a pure
predicate; it was **sticky** — set true, never reset — so on a shared singleton one disabling cart
poisoned every later cart in the request.

Dead members to delete with the classes: `CARRIER_TYPE_CUSTOM`, `isPickupMailboxActive()` and its
setter and config read, `PackageInterface::addWeight()`, `Package::setPackageType()`/`getPackageType()`,
and `PackageInterface` itself (implemented only by `Package`). `Checkout::hideDeliveryOptionsForProduct()`
goes too — its one caller at `Checkout.php:85` exists solely to prime the flag.

### Proving behaviour did not move

`Tests/Helpers/PackageTypeCases.php` holds one table of `[items, country, carrier, config,
candidates, expected]` rows. **Two drivers consume the same array**: a BEFORE driver against
`PackageRepository` (added before the rewrite, deleted with it) and an AFTER driver against
`PackageTypeResolver`. Because the expected values are one shared array rather than two
transcriptions, "behaviour did not change" is proved by the table, and it satisfies the
no-duplicate-code rule.

Rows that must be there: the fixed order digital_stamp → mailbox → package_small → package;
`fit_in_mailbox` of `-1`, `0`, `0` with zero weight, and `null` (the fix above); the "every item must
be a digital stamp" rule at `:68-70`; items with `qty < 1` skipped; percentage accumulating past 100;
`weight_indication` kilo vs gram; non-NL PostNL through `hasPostnlMailboxInternational()`; and the
non-NL weight quirk where `setMailboxSettings()` returns early so `maxMailboxWeight` stays `0`.

Add `Tests/Unit/Service/StatelessServicesTest.php` — a reflection assertion that neither service
declares a property beyond its injected collaborators. One line, permanent, and the only thing that
stops the state creeping back.

## Drift the regeneration surfaces

Generating from one catalogue makes every mismatch between "a field exists" and "a path is read"
visible. The 81 orphan `config.xml` paths triage into three groups — do the pruning as a **separate
commit** so the default changes are reviewable alone:

- **Still read, so give it a real field**: `general/print/return_in_the_box`
  (`MagentoCollection.php:202`).
- **`*/mailbox/pickup_mailbox` is dead — delete the whole chain.** You were right, and one of my
  designers called it load-bearing in error. The full trace is nine lines and ends nowhere:
  three `config.xml` defaults (`:103`, `:152`, `:252`), the read and setter at
  `PackageRepository.php:199-200`, and the property, setter and getter on `Package.php:59, 183-185,
  191-193`. **`isPickupMailboxActive()` has exactly one mention in the codebase — its own
  declaration.** Nothing reads it, and there has never been a setting for it. `Package.php` is being
  deleted anyway, so only the three `config.xml` defaults need removing by hand.
- **Read only by the upgrade script, safe to delete**: the 48 `*/general/{cutoff_time,
  deliverydays_window, dropoff_days, dropoff_delay, monday_delivery_active, saturday_cutoff_time}`.
  `UpgradeData.php:759-775` reads them with raw SQL against `core_config_data`, not through
  `scopeConfig`, so it only ever sees rows a merchant saved. Pin that with a test.
- **Dead, delete**: the remaining 31.

One more: `cutoff_time_same_day_{0..6}` is read by `Checkout::getDropOffDays()` (`Checkout.php:320`)
and exists in neither the JSON nor `config.xml`. It is guarded, so it is harmless, but
`cutoffTimeSameDay` has never reached the widget. Generate the field or delete the read.

`CLAUDE.md` lines 105-116 and 130-134 both become wrong — a new setting is a `Catalogue` entry, and
a new carrier needs nothing at all.

## Verification

- `composer install && vendor/bin/pest`, then `rm -rf vendor` before compiling.
- `php -dmemory_limit=-1 bin/magento setup:di:compile` with the module's `vendor` moved aside.
- `cache:clean`, then open **Stores → Configuration → MyParcel** at default, website and store
  scope. Carrier sections must differ when the scopes hold different API keys.
- `private/config-source-probe.php` — defaults resolve, and `config.xml` still beats them.
- A second API key on a website scope whose account has `order_mode` on: that scope exports as PPS,
  the other as shipments, with no setting anywhere.
- Point one scope's key at an account holding a carrier the module has never shipped settings for.
  It appears in the settings with its toggle **off**, and does not reach the checkout until saved.
- Remove a carrier from an account and confirm the retired group renders so it can be switched off.
- `no_tracking`: appears only under the package types capabilities reports it for, has **no fee
  field**, and defaults off — so tracking stays on until the merchant opts out. Then switch it on
  and confirm it reaches the shipment, which is what the opened key lists buy.
- An option set at tier 3 (the customer's checkout choice) is not overridden by a conflicting tier 4
  configuration default, and two options at the same tier both survive to the API.
- Two stores on **different propositions**: the checkout widget receives each one's own `platform`,
  and a bulk export across both produces labels on each store's own account.
- **The case that prompted this**: an order with age check but no signature, on a carrier whose
  contract requires signature with age check. The export adds signature, logs that it did, and the
  API accepts. Confirm the label comes back rather than a rejection.
- Receipt code on PostNL adds insurance and **stops** — signature and only recipient stay off, so
  the chain does not enable what receipt code excludes.
- `default_options`: auto-forcing two mutually excluding options is rejected on save, per field, with
  the other fields still saving. On a permissive set nothing is rejected and nothing is added.
- `private/caps-cache-dump.php` to see what the capabilities cache holds while testing.

## Risks, cheapest check first

| risk | check |
|---|---|
| The virtualType extension replaces `sources` rather than merging | Spike assertion 2: `dev:di:info` shows four entries. ~2 min. |
| sortOrder 5 is not below `modular`, so generated `0`s overwrite grandfathered `1`s | Spike assertion 4: `config:show …/postnl_settings/delivery/active` prints `1`. One command. |
| Something in the source's graph reaches `ScopeConfigInterface` and the config build recurses | A reflection assertion in the unit test, so it fails before Magento boots. |
| **Deriving PPS switches merchants who chose "shipping details only" on an order-mode account** | Count them: `SELECT scope, scope_id, value FROM core_config_data WHERE path LIKE '%print/export_mode'`. This is the one intended behaviour change — see below. |
| The `ups → upsstandard` migration misses a path | `grep -rn "ups_settings"` across `src/ etc/ view/ Controller/ Tests/`, and a test that reads a renamed setting after the migration. |
| An option name derives to a path that differs from the stored one, so a merchant's saved value is orphaned | A test that round-trips all 14 current names through derive-then-alias and gets the stored `core_config_data` segment back. Six of them only pass via the alias table, so the test fails loudly if a row is dropped. |
| A merchant enables a discovered carrier the SDK cannot export, and every order fails at label time | The `isValid()` gate, plus a test that a carrier absent from `CarrierApiMapping` renders disabled and cannot be switched on. |
| `toV2Name()` answers null for a discovered carrier on the export path, so `carrierId()` throws | Pin the fallback order: legacy map, then the stored capability answer, then null. Test an order whose `myparcel_carrier` is a derived name. |
| A `requires` pass that recurses enables the very options an exclusion exists to prevent | Single level, pinned by a test on PostNL's receipt code → insurance → signature chain. The dependency doc names this as the PDK's trap. |
| The export adds an option the customer never paid for, silently | Log every addition with the order and what required it. Surface it in the export report if that is cheap. |
| The provenance ranking silently drops an age check on 18+ goods | Equal tiers are never arbitrated, and tier 1 is the product attribute. Pin both with a test; `docs/sdk-v11.md:62-66` is the rule. |
| Appending to `ShipmentOptions::KEYS` disturbs the persisted key order | Append only, never reorder. A test that reads back an order stored before the change. |
| A permissive set makes the export add options or the validator reject a save | Explicit early return on `isPermissive()` in both, with a test each — the dependency doc calls this out. |
| Generated options gain fee fields nine of them never had | Fees are opt-in. The parity test against the deleted JSON catches any extra `*_fee` path at any carrier, `no_tracking` included. |
| The proposition-id → platform-name map is wrong or incomplete | Check the SDK for an existing map before writing one; assert the widget payload for a known non-`myparcel` account. |
| Label drift silently drops six locales | Parity test comparing labels against a byte copy of the deleted JSON. |
| A carrier that leaves the contract keeps its grandfathered `active = 1` | The retired group, plus a test that drops a carrier from the fixture. |
| Per-scope emission flips a merchant off via `Fallback`'s merge | Avoided by design; assert `get('')` has no `websites`/`stores` keys. |
| The cron split changes which orders get polled | Its own commit and its own test, so it reverts alone. |
| `app:config:dump` locks settings, and an `env.php` API key is invisible to a source reading `core_config_data` | `grep -n "'system'" app/etc/config.php app/etc/env.php`. Same limitation `RuntimeConfigSource` has; document, do not fix here. |

## The one intended behaviour change

Deriving export mode from the account means a merchant whose account **has** order mode but who
deliberately chose *"Export shipping details only"* is switched to PPS on upgrade. That is the
direct consequence of removing the setting, and it is the only place this plan changes what a live
shop does. Count the affected rows before shipping, and put it in the release notes as a breaking
change. If the count is non-trivial, the alternative is to keep the setting but offer `pps` only
where the account allows it — say so and it is a small edit to step 9.

## Still open

1. **How far the config source goes** — all `myparcelnl_*` defaults or activation only. Decided
   after the spike, per your call.
2. **INT-1694**, referenced by the ticket for `no_tracking`. Worth reading for anything it says that
   capabilities does not, since "no surcharge" already turned out to be exactly that.
3. **Per-carrier delivery titles** stay deferred. The override layer is additive, so deciding later
   costs nothing.

Settled since the first draft: INT-1286 lands first; `requires`/`excludes` is in this plan and gets
no separate ticket; `no_tracking` ships here; `*/mailbox/pickup_mailbox` is dead and goes.

## Branch

Base INT-1289 on `feat/use-sdk-v11-shipments` once its PR is open. That branch is 6 commits ahead of
`main` and owns `src/Model/Shipment/Capabilities/`, which every part of this plan reads.
