# TR-000005: SDK v11 API Mapping and Constant Ownership

## Related Functional Requirements

- [FR-000006 — Shipment export via SDK v11 shipment services](../functional-requirements/FR-000006-shipment-export-via-sdk-v11.md)
- [FR-000008 — Carrier capabilities and contract definitions](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md)
- [FR-000009 — Insurance as a range](../functional-requirements/FR-000009-insurance-as-a-range.md)
- [FR-000010 — Graceful degradation on capability changes](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md)

## Related ADRs

- None yet. An ADR for *"the Magento module owns its shipment domain layer"* is worth raising in [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr), since that boundary is now permanent rather than borrowed from the SDK.

## Category

Compatibility

## Requirement

Every SDK symbol the module uses that is removed at `v11.0.0-beta.31` must be replaced by a named, documented equivalent — either an SDK v11 service, or a module-owned class. No module code may reference a class, method or constant that does not exist at beta.31, and `composer.json` must require `11.0.0-beta.31@beta`.

Constants that describe carrier behaviour (which package types exist, which options exist, country zones) become **module-owned**, sourced from the SDK's v11 mapping classes where a name-to-id translation is needed. Values that describe a *merchant's contract* are not constants and are covered by [TR-000007](TR-000007-capabilities-retrieval-and-storage.md).

## Rationale

SDK beta.22 deleted 60 files: the whole consignment stack, the delivery-options adapters, `MyParcelCollection`, the factories, the consignment validators and rules, and several helpers. The module has 54 files touching the SDK, 34 of which touch something removed. Without an explicit mapping, the migration becomes 34 independent judgement calls, each an opportunity for silent behaviour drift in a codebase with no test coverage over this area.

## Specifications

### Capability mapping: removed → replacement

| Removed at beta.22, absent at beta.31 | Replacement |
|---|---|
| `Helper\MyParcelCollection` + `Model\Consignment\AbstractConsignment` | `Collection\ShipmentCollection` + `Model\Shipment\Shipment` |
| `Factory\ConsignmentFactory::createByCarrierName()` | `(new Shipment())->setCarrier(...)` |
| `MyParcelCollection::createConcepts()` | `Services\Shipment\ShipmentCreateService::create()` |
| `MyParcelCollection::setLatestData()` | `Services\Shipment\ShipmentQueryService::find()` / `findMany()` / `findByReferenceId()` |
| `MyParcelCollection::deleteConcepts()` | `Services\Shipment\ShipmentDeleteService::deleteMany()` |
| `MyParcelCollection::setPdfOfLabels()` / `downloadPdfOfLabels()` | `Services\Labels\ShipmentLabelsService` |
| `MyParcelCollection::generateReturnConsignments()` | `Services\Returns\ReturnShipmentService::createRelated()` |
| `MyParcelCollection::addMultiCollo()` | `Services\MultiCollo\MultiColloShipmentService::splitShipment()` |
| `$consignment->getBarcode()` / status | `ShipmentQueryService::find()`, or `Services\TrackTrace\ShipmentTrackTraceService::fetchTrackTraceData()` for full history |
| `Adapter\DeliveryOptions\*`, `Factory\DeliveryOptionsAdapterFactory` | Module-owned value objects under `src/Adapter/DeliveryOptions/` |
| `Helper\TrackTraceUrl` | Module-owned `src/Service/TrackTraceUrl`. The class stays; its hard-coded base URL does not — Phase 7 replaces that with `link_consumer_portal` / `link_tracktrace` from the track & trace response, since no account setting carries a base URL |
| `Helper\ValidatePostalCode` | **Removed, not replaced.** The API is the only authority on address validity — see DR-11 |
| `AbstractConsignment::canHave*()`, `getAllowed*()`, `getInsurancePossibilities()` | Capability data — see [TR-000007](TR-000007-capabilities-retrieval-and-storage.md) |
| `AbstractConsignment::isToRowCountry()`, `getLocalCountryCode()` | Module constants over `Services\CountryCodes` (**not** a network call) |
| Carrier `::CONSIGNMENT` constants, `getConsignmentClass()` | Removed with no equivalent; nothing may depend on them |
| `Model\PrinterlessReturnRequest(AbstractConsignment)` | `Model\PrinterlessReturnRequest(string $apiKey, int $consignmentId)` |

### Constant ownership

| New module class | Replaces |
|---|---|
| `src/Model/Shipment/CountryCode` | `AbstractConsignment::CC_*`, `EURO_COUNTRIES` — see the exception below |
| `src/Model/Shipment/PackageType` | `AbstractConsignment::PACKAGE_TYPE_*`, `PACKAGE_TYPES_*_MAP`, `PACKAGE_TYPES_IDS` |
| `src/Model/Shipment/DeliveryType` | `AbstractConsignment::DELIVERY_TYPE_*`, `DELIVERY_TYPES_NAMES_IDS_MAP`, `DEFAULT_DELIVERY_TYPE` |
| `src/Model/Shipment/ShipmentOption` | `AbstractConsignment::SHIPMENT_OPTION_*`, `EXTRA_OPTION_*` |

**Ids** are sourced from the SDK's generated `Client\Generated\CoreApi\Model\{RefShipmentPackageType, RefTypesDeliveryType}`, so no wire value is hard-coded in the module. **Names are not**: the module's snake_case names (`letter`, `package_small`, `standard`) are persisted in `core_config_data`, in the order's delivery-options JSON and in the versioned REST v1 contract, while the SDK's v2 vocabulary calls the same things `UNFRANKED`, `SMALL_PACKAGE` and `STANDARD_DELIVERY`. The module keeps its own names and translates at the API boundary; `Model\Rest\Transformer\PackageTypeTransformer::LEGACY_NAME_MAP` is the existing precedent.

`Model\Shipment\Mapping\DeliveryTypeApiMapping` exists only from beta.31, so nothing developed against the installed beta.15 may depend on it. The generated ref models are present and identical at both tags and are used instead.

#### Which vocabulary crosses which boundary

Four boundaries, three vocabularies. Getting this wrong is silent at compile time and loud at runtime, so it is tabulated rather than left to inference. Checked against the beta.31 tag on 2026-08-17.

| Boundary | Vocabulary | Established by |
|---|---|---|
| Shipment create — the outbound path (Phase 6) | **integer id** | `ShipmentOptions::openAPITypes()` forces `package_type` to `int`; `setPackageType()`/`setDeliveryType()` coerce a string to an id before storing |
| Capabilities request and response (Phase 4) | **v2 enum name** (`SMALL_PACKAGE`, `STANDARD_DELIVERY`) | `CapabilitiesRequest::withPackageType()` documents `RefShipmentPackageTypeV2::PACKAGE`; the response's `getPackageTypes()` returns the same |
| Versioned REST v1 endpoint (already built) | Order API enum name | `Model\Rest\Transformer\PackageTypeTransformer` |
| `core_config_data`, the order's delivery-options JSON, the checkout widget | **module snake_case** (`package_small`, `standard`) | persisted data; cannot change without a migration |

**The operative rule: give the SDK an id, never a module name.** `Model\Shipment\PackageType::isValid('letter')` and `isValid('package_small')` both return **false** — the SDK knows only `UNFRANKED` and `SMALL_PACKAGE` — so `ShipmentOptions::setPackageType('letter')` throws `InvalidArgumentException`. Verified by running it against the installed SDK. Every module call site already passes an id (`TrackTraceHolder.php:184`, `Checkout.php:189`), so Phase 6 inherits a correct outbound path and needs no name translation at all.

**Name translation is needed in one direction only:** v2 to ours, on the way in from capabilities, in Phase 4. When that map is written it belongs on the facades, and `PackageTypeTransformer::LEGACY_NAME_MAP` should then read from it rather than holding a second copy of the same knowledge.

**An unknown *id* is sendable at beta.31, an unknown *name* is not.** Tested at both tags, because the answer reversed between them:

| | `setPackageType(31)` | `setDeliveryType(99)` |
|---|---|---|
| beta.15 | rejected — "must be one of 1…7" | rejected — "must be one of 1…8" |
| beta.31 | stored and serialized | stored and serialized |

beta.29's enum-validation loosening removed the checks from the generated setters, so `RefShipmentShipmentOptions` now rejects only null. An unknown id therefore reaches the API and the API decides — which is what lets Phase 3 carry an unrecognised type through rather than substituting a default (DR-12, FR-000010). A non-numeric unknown still throws locally, because `ShipmentOptions::setPackageType()` resolves strings through the mapping while an int bypasses it. Both end in a named error; neither ends in a silent substitution.

Note this only holds once the pin moves. Until Phase 9 the installed beta.15 rejects unknown ids locally, so an integration test for the carry-through path must run against beta.31.

#### The names survive; the fixed list does not

Asked in review and worth settling once. The **ids** are no longer module-owned in any meaningful sense — they are aliases of the generated ref models. The **names** cannot be given up, for three reasons none of which are ours to change:

1. They are `core_config_data` **path segments** — `empty_package_weight/package_small`, `delivery_titles/package_small_title`. Renaming means a data migration.
2. Every historical order carries them in its delivery-options JSON, read at export, re-export and in the admin form.
3. The CDN-loaded `@myparcel-dev/delivery-options` widget emits them; the module does not control its protocol.

So the end state is not "no constants". It is that `PackageType::IDS` / `NAMES` and the `DeliveryType` equivalents **dissolve into capabilities in Phase 4**, while a small legacy↔v2 name map remains permanently. Until then the facades name all seven SDK types rather than the five the module has code for: naming a type is what allows it to be rejected legibly instead of silently replaced (DR-12). A list of seven is no less an allow-list than a list of five, which is why it is transitional and why FR-000010 forbids treating it as authoritative.

Every new constant must equal the beta.15 SDK value it replaces, asserted by unit test — **with one exception**. `EURO_COUNTRIES` deliberately does not: the beta.15 list holds `XK` (Kosovo) and omits `MT` (Malta), and `Services\CountryCodes::EU_COUNTRIES` is the other way round and correct. beta.31's own `Concerns\HasCountry` already reads the new list. The unit test asserts the `MT`/`XK` delta explicitly rather than asserting equality. Rationale in DR-9 of [the migration plan](../design/sdk-v11-migration.md).

Surviving at beta.31 and still used: `Services\CountryCodes`, `Support\Str`, `Support\Collection`, `Model\Recipient`, `Model\CustomsDeclaration`, `Model\MyParcelCustomsItem`, `Model\PickupLocation`, `Helper\SplitStreet`, `Model\Carrier\*`. `Helper\ValidateStreet` also survives but the module no longer calls it (DR-11) — leave it in the SDK, do not move it into the module, and do not re-introduce a call. Several are now marked `@internal` — including `AccountWebService`, `CarrierOptionsWebService`, `Collection\Fulfilment\OrderCollection`, and the carrier value objects — so they work but should not attract new usage.

### Changed signatures on surviving classes

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` now returns `Model\Shipment\ShipmentOptions`; `setDeliveryOptions()` takes it. `getCarrierId()` / `setCarrierId()` are new, and **`getCarrier()` throws if the carrier id was never set**.
- `Model\MyParcelCustomsItem::setDescription($description, $carrier = null)` keeps its signature but **ignores `$carrier`**; the maximum length is hard-coded to 50 rather than resolved per carrier.
- `Model\Carrier\AbstractCarrier` gains `TYPE_B2C` / `TYPE_B2B`, relocated from `AbstractConsignment`.
- `Services\Labels\ShipmentLabelsService::downloadPdfOfLabels()` sends headers and exits, as before. The ordering constraint that follows from it is in [TR-000006](TR-000006-per-api-key-export-batching.md).

### Recorded divergences from `myparcelnl/pdk`

Three, all deliberate. A future reader finding them should treat them as decisions, not omissions.

1. **We reuse the SDK's `CapabilitiesMapper::mapToCoreApi()`** rather than porting PDK's private `hydrateModel()`. The mapper builds typed generated models through typed setters, so wire keys come from each model's own `attributeMap` — there is no case conversion to get wrong. It also maps our legacy option names to the V2 names (`signature → setRequiresSignature`, `only_recipient → setRecipientOnlyDelivery`, `age_check → setRequiresAgeVerification`, `receipt_code → setRequiresReceiptCode`, `large_format → setOversizedPackage`, `collect → setScheduledCollection`, `return → setReturnOnFirstFailedDelivery`, `printerless_return → setPrintReturnLabelAtDropOff`), which is exactly the translation this module needs, and it normalises `null` to an empty object because `null` means "enabled, unconfigured" while `false` and `0` are meaningful. Reimplementing that risks getting all three wrong.
2. **We do not port `filterSupportedCapabilities()`.** See [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md). A capability allow-list is the mechanism by which upstream additions break integrations.
3. **We do not port `InsuranceTierMath`.** See [FR-000009](../functional-requirements/FR-000009-insurance-as-a-range.md). The API accepts any amount in range; a synthesised ladder invents steps the API does not have.

### Prerequisite hand-off

The pull request that hashes the API key out of the `account_settings_{apiKey}` config path lands first and carries no requirement documents. **Its final config path shape is recorded here** once known, because Phase 5 writes contract definitions alongside it.

> _To be filled in when the prerequisite PR merges: final config path, and the name and location of the shared hash helper (also referenced by TR-000007)._

## Verification Method

Static verification, since this is a compatibility requirement rather than a measurable one.

### Test Scenarios

1. **No removed symbol remains.** Grep `src/`, `Controller/`, `view/` and `Tests/` for every FQCN in the removed-class inventory; expect zero matches. This includes `view/adminhtml/templates/new_shipment.phtml`, which uses the consignment API directly and which a PHP-only grep misses.
2. **Constant equivalence.** Unit tests assert each new module constant equals the beta.15 SDK value it replaces, run while beta.15 is still installed.
3. **DI compilation.** `bin/magento setup:di:compile` succeeds, and neither `src/Block/Sales/OrderAction.php` nor `ShipmentAction.php` takes an SDK consignment as a constructor argument any more. This is the only check that catches those two, since they have no `di.xml` entry to grep for. Phase 2 owns the fix (DR-10).
4. **Composer resolution.** `composer update myparcelnl/sdk` resolves beta.31 with `setasign/fpdi` present as an explicit dependency, and the Pest suite passes on PHP 8.1 through 8.4.
5. **Dead import sweep.** Unused imports in `MagentoCollection`, `MagentoOrderCollection`, `TrackTraceHolder` and `AccountSettings`; the `AccountSettings` pair name classes absent from **both** beta.15 and beta.31, so they were already broken. Done in Phase 2, together with thirteen others found in the same sweep — this scenario is now a regression check, not open work.

### Monitoring

None ongoing. This requirement is satisfied once and enforced thereafter by CI.

## Assumptions

Each entry carries its own check date. Kept rather than deleted, because each was load-bearing while it was open.

- beta.31 is the target. Later betas are ordinary maintenance, not this requirement. **Confirmed 2026-08-14** — beta.31 is still the highest `v11` tag on the remote.
- ~~The v11 shipment stack is byte-identical between beta.15 and beta.31 for the model and collection.~~ **Overstated.** `Model\Shipment\Shipment` and `Collection\ShipmentCollection` are byte-identical, and the generated `RefShipmentShipmentOptions` carries every setter the port needs at both versions — so the conclusion holds and the port can be built against the installed SDK. But `Model\Shipment\ShipmentOptions` is **not** identical: beta.31 adds `getDeliveryType()`, `setDeliveryType()`, `getInsurance()`, `toArray()`, `toArrayWithoutNull()` and `fromOrderResponse()`, plus a new `Mapping\DeliveryTypeApiMapping`. The one behavioural difference that matters: beta.31's `setDeliveryType()` normalises a string enum name to an id, where beta.15 stores the string unchanged. **Pass delivery type as an int and the two versions agree** — the module already does, via `getDeliveryTypeId()`. Re-verify this specific call after the pin moves.
- PHP floor stays `^7.4 || ^8.0`; beta.31 does not raise it. **Confirmed 2026-08-14** — both tags declare `"php": "^7.4 || ^8.0"`.
- ~~Replacing a constant with its SDK v11 equivalent is behaviour-neutral.~~ **False for one of them.** The EU country list changed membership between the two sources (Malta in, Kosovo out), so the swap moves those two destinations between the EU and ROW branches at three call sites. Checked against both tags on 2026-08-17. Carried as the documented exception above rather than as a surprise.
- ~~`ValidateStreet` keeps working, so street validation stays.~~ **Superseded by DR-11**: it survives at beta.31 but is no longer called. Address validation was removed whole rather than left half-ported.
- `Helper\SplitStreet` and `Helper\ValidateStreet` survive at beta.31 and must not be moved into the module. **Confirmed 2026-08-17** — beta.31's `src/Helper/` contains exactly `MyParcelCurl`, `RequestError`, `SplitStreet` and `ValidateStreet`. Only `TrackTraceUrl` and `ValidatePostalCode` are gone.

## Constraints

- No `vendor/**` file may be modified.
- The module is non-functional against beta.31 until the migration completes. Intermediate commits target beta.15–beta.21, where both stacks coexist.
- `guzzlehttp/guzzle ^7.10` is required transitively by the generated client. Already satisfied (7.10.0 installed) but a hard floor on older Magento patch releases.
