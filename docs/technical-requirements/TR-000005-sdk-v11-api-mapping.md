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

| Removed at beta.31 | Replacement |
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
| `Helper\TrackTraceUrl` | Module-owned `src/Service/TrackTraceUrl` |
| `Helper\ValidatePostalCode` | Module-owned `src/Service/ValidatePostalCode` |
| `AbstractConsignment::canHave*()`, `getAllowed*()`, `getInsurancePossibilities()` | Capability data — see [TR-000007](TR-000007-capabilities-retrieval-and-storage.md) |
| `AbstractConsignment::isToRowCountry()`, `getLocalCountryCode()` | Module constants over `Services\CountryCodes` (**not** a network call) |
| Carrier `::CONSIGNMENT` constants, `getConsignmentClass()` | Removed with no equivalent; nothing may depend on them |
| `Model\PrinterlessReturnRequest(AbstractConsignment)` | `Model\PrinterlessReturnRequest(string $apiKey, int $consignmentId)` |

### Constant ownership

| New module class | Replaces |
|---|---|
| `src/Model/Shipment/CountryCode` | `AbstractConsignment::CC_*`, `EURO_COUNTRIES` |
| `src/Model/Shipment/PackageType` | `AbstractConsignment::PACKAGE_TYPE_*`, `PACKAGE_TYPES_*_MAP`, `PACKAGE_TYPES_IDS` |
| `src/Model/Shipment/DeliveryType` | `AbstractConsignment::DELIVERY_TYPE_*`, `DELIVERY_TYPES_NAMES_IDS_MAP`, `DEFAULT_DELIVERY_TYPE` |
| `src/Model/Shipment/ShipmentOption` | `AbstractConsignment::SHIPMENT_OPTION_*`, `EXTRA_OPTION_*` |

Name-to-id translation delegates to SDK `Model\Shipment\{PackageType, Carrier}` and `Model\Shipment\Mapping\*`. Every new constant must equal the beta.17 SDK value it replaces, asserted by unit test.

Surviving and to be left alone: `Services\CountryCodes`, `Support\Str`, `Support\Collection`, `Model\Recipient`, `Model\CustomsDeclaration`, `Model\MyParcelCustomsItem`, `Model\PickupLocation`, `Helper\SplitStreet`, `Helper\ValidateStreet`, `Model\Carrier\*`. Several are now marked `@internal` — including `AccountWebService`, `CarrierOptionsWebService`, `Collection\Fulfilment\OrderCollection`, and the carrier value objects — so they work but should not attract new usage.

### Changed signatures on surviving classes

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` now returns `Model\Shipment\ShipmentOptions`; `setDeliveryOptions()` takes it. `getCarrierId()` / `setCarrierId()` are new, and **`getCarrier()` throws if the carrier id was never set**.
- `Model\MyParcelCustomsItem::setDescription($description, $carrier = null)` keeps its signature but **ignores `$carrier`**; the maximum length is hard-coded to 50 rather than resolved per carrier.
- `Model\Carrier\AbstractCarrier` gains `TYPE_B2C` / `TYPE_B2B`, relocated from `AbstractConsignment`.
- `Services\Labels\ShipmentLabelsService::downloadPdfOfLabels()` sends headers and exits, as before.

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
2. **Constant equivalence.** Unit tests assert each new module constant equals the beta.17 SDK value it replaces, run while beta.17 is still installed.
3. **DI compilation.** `bin/magento setup:di:compile` succeeds. This is the only check that catches `src/Block/Sales/OrderAction.php:41` and `ShipmentAction.php:48`, which take an SDK consignment as a constructor argument with no `di.xml` entry to grep for.
4. **Composer resolution.** `composer update myparcelnl/sdk` resolves beta.31 with `setasign/fpdi` present as an explicit dependency, and the Pest suite passes on PHP 8.1 through 8.4.
5. **Dead import sweep.** `MagentoCollection.php:47`, `MagentoOrderCollection.php:35`, `TrackTraceHolder.php:41` and `AccountSettings.php:13,15` carry unused imports; the last two name classes absent from **both** beta.17 and beta.31, so they are already broken and must be deleted.

### Monitoring

None ongoing. This requirement is satisfied once and enforced thereafter by CI.

## Assumptions

- beta.31 is the target. Later betas are ordinary maintenance, not this requirement.
- The v11 shipment stack is byte-identical between beta.15 and beta.31 for the model and collection, so the port can be built against the installed SDK with the legacy stack as a live reference.
- PHP floor stays `^7.4 || ^8.0`; beta.31 does not raise it.

## Constraints

- No `vendor/**` file may be modified.
- The module is non-functional against beta.31 until the migration completes. Intermediate commits target beta.15–beta.21, where both stacks coexist.
- `guzzlehttp/guzzle ^7.10` is required transitively by the generated client. Already satisfied (7.10.0 installed) but a hard floor on older Magento patch releases.
