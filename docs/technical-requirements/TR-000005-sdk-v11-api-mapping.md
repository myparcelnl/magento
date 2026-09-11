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

Every SDK symbol the module used that v11 removed is replaced by a named equivalent: an SDK v11 service, or a module-owned class. No module code references a class, method or constant that does not exist at the pinned tag, and `composer.json` requires `11.0.0-beta.33@beta`.

Constants that describe carrier behaviour (which package types exist, which options exist, country zones) are **module-owned**, with ids sourced from the SDK's generated models. Values that describe a *merchant's contract* are not constants and are covered by [TR-000007](TR-000007-capabilities-retrieval-and-storage.md).

## Rationale

SDK beta.22 deleted the whole consignment stack, the delivery-options adapters, `MyParcelCollection`, the factories, the validators and several helpers. The module touched something removed in 34 files. Without an explicit mapping the migration becomes 34 independent judgement calls, each an opportunity for silent behaviour drift in code that had no test coverage.

## Specifications

### Capability mapping: removed → replacement

| Removed | Replacement |
|---|---|
| `Helper\MyParcelCollection` + `Model\Consignment\AbstractConsignment` | `Collection\ShipmentCollection` + `Model\Shipment\Shipment` |
| `Factory\ConsignmentFactory::createByCarrierName()` | `(new Shipment())->setCarrier(...)` |
| `MyParcelCollection::createConcepts()` | `Services\Shipment\ShipmentCreateService::create()` |
| `MyParcelCollection::setLatestData()` | `Services\Shipment\ShipmentQueryService::find()` / `findMany()` / `findByReferenceId()` |
| `MyParcelCollection::deleteConcepts()` | `Services\Shipment\ShipmentDeleteService::deleteMany()` |
| `MyParcelCollection::setPdfOfLabels()` / `downloadPdfOfLabels()` | `Service\Export\LabelHttpClient` fetching per key, `Service\Export\LabelPdfMerger` merging |
| `MyParcelCollection::generateReturnConsignments()` | `Services\Returns\ReturnShipmentService::createRelated()` |
| `MyParcelCollection::addMultiCollo()` | `Services\MultiCollo\MultiColloShipmentService::splitShipment()` |
| `MyParcelCollection::setUserAgents()` | Module-owned `Service\UserAgent`. `map()` goes to anything carrying the SDK's `HasUserAgent` trait, `header()` to the callers that build their own header string. There is no SDK type to hint against, so the map is returned rather than an object tagged |
| `$consignment->getBarcode()` / status | `ShipmentQueryService::find()`, or `Services\TrackTrace\ShipmentTrackTraceService::fetchTrackTraceData()` for full history |
| `Adapter\DeliveryOptions\*`, `Factory\DeliveryOptionsAdapterFactory` | Module-owned value objects under `src/Adapter/DeliveryOptions/`: `DeliveryOptions`, `ShipmentOptions`, `PickupLocation`, `DeliveryOptionsFactory`. `Helper\ShipmentOptions` was the resolver, not a value object; it is `Service\ShipmentOptionsResolver`. `Model\Shipment\OrderShipmentOptions` builds the SDK's `Model\Shipment\ShipmentOptions` for both export paths |
| `Helper\TrackTraceUrl` | The link comes from the API: `ShipmentApi::getShipmentsById($ids, $link_consumer_portal, $userAgent)` fills `getLinkConsumerPortal()`, stored in `sales_shipment_track.myparcel_tracktrace_url`. `ShipmentQueryService::findMany()` hard-codes that argument to `null`, so `Service\Export\ShipmentQuery` makes the call. `Service\TrackTraceUrl` is the fallback; its host follows the account's platform, read from the stored account settings |
| `Helper\ValidatePostalCode` | **Removed, not replaced.** The API is the only authority on address validity (BR-000003) |
| `AbstractConsignment::canHave*()`, `getAllowed*()`, `getInsurancePossibilities()` | Capability data, [TR-000007](TR-000007-capabilities-retrieval-and-storage.md) |
| `AbstractConsignment::isToRowCountry()`, `getLocalCountryCode()` | Module constants over `Services\CountryCodes`, never a network call |
| Carrier `::CONSIGNMENT` constants, `getConsignmentClass()` | Removed with no equivalent |

### Constant ownership

| Module class | Replaces |
|---|---|
| `src/Model/Shipment/CountryCode` | `AbstractConsignment::CC_*`, `EURO_COUNTRIES` — see the exception below |
| `src/Model/Shipment/PackageType` | `AbstractConsignment::PACKAGE_TYPE_*`, `PACKAGE_TYPES_*_MAP` |
| `src/Model/Shipment/DeliveryType` | `AbstractConsignment::DELIVERY_TYPE_*`, `DELIVERY_TYPES_NAMES_IDS_MAP`, `DEFAULT_DELIVERY_TYPE` |
| `src/Model/Shipment/ShipmentOption` | `AbstractConsignment::SHIPMENT_OPTION_*`, `EXTRA_OPTION_*` |
| `src/Model/Shipment/Type/{PackageTypeValue,DeliveryTypeValue}` | New. A stored type that can hold a value the module does not recognise, so a read path never substitutes one |

**Ids** come from the generated `Client\Generated\CoreApi\Model\{RefShipmentPackageType, RefTypesDeliveryType}`, so no wire value is hard-coded. **Names are the module's own**: the snake_case names (`letter`, `package_small`, `standard`) are persisted in `core_config_data`, in every order's delivery-options JSON and in the versioned REST v1 contract, while the SDK's v2 vocabulary calls the same things `UNFRANKED`, `SMALL_PACKAGE` and `STANDARD_DELIVERY`. The module keeps its names and translates at the API boundary.

#### Which vocabulary crosses which boundary

| Boundary | Vocabulary | Established by |
|---|---|---|
| Shipment create, the outbound path | **integer id** | `ShipmentOptions::openAPITypes()` forces `package_type` to `int`; `setPackageType()` / `setDeliveryType()` coerce a string to an id before storing |
| Capabilities request and response | **v2 enum name** (`SMALL_PACKAGE`, `STANDARD_DELIVERY`) | `CapabilitiesRequest::withPackageType()`; the response's `getPackageTypes()` |
| Versioned REST v1 endpoint | Order API enum name | `Model\Rest\Transformer\PackageTypeTransformer` |
| `core_config_data`, the order's delivery-options JSON, the checkout widget | **module snake_case** | Persisted data; cannot change without a migration |

**The operative rule: give the SDK an id, never a module name.** `ShipmentOptions::setPackageType('letter')` throws, because the SDK knows only `UNFRANKED`. Every module call site passes an id.

**An unknown id is sendable, an unknown name is not.** The generated setters reject only null, so an unknown id reaches the API and the API decides, which is what lets an unrecognised stored type travel rather than be replaced (FR-000010). `Model\Shipment\Type\AbstractTypeValue::toApiValue()` implements it: a resolved type gives its id, an unresolved number passes through, an unresolved name throws naming itself. Both end in a named error; neither in a substitution.

#### The names survive; the fixed list does not

The ids are aliases of the generated models. The names cannot be given up, for three reasons none of which the module controls: they are `core_config_data` **path segments** (`empty_package_weight/package_small`); every historical order carries them in its delivery-options JSON; and the CDN-loaded `@myparcel-dev/delivery-options` widget emits them. So a small legacy↔v2 name map remains permanently, and the facades name all seven SDK types rather than the five the module has code for: naming a type is what lets it be rejected legibly instead of replaced. A list of seven is no less an allow-list than one of five, which is why FR-000010 forbids treating it as authoritative.

Every module constant equals the beta.15 SDK value it replaced, **with one exception**. `CountryCode::EURO_COUNTRIES` follows `Services\CountryCodes::EU_COUNTRIES`, which holds Malta and omits Kosovo; the beta.15 list had it the other way round and was wrong. A Malta shipment stops getting a customs declaration and drops to the EU insurance zone; a Kosovo shipment gains one and moves to ROW.

Surviving in v11 and still used: `Services\CountryCodes`, `Support\Str`, `Support\Collection`, `Model\Recipient`, `Model\CustomsDeclaration`, `Model\MyParcelCustomsItem`, `Model\PickupLocation`, `Helper\SplitStreet`, `Model\Carrier\*`. `Helper\ValidateStreet` survives but the module no longer calls it; do not move it into the module and do not re-introduce a call. Several are marked `@internal`, including `AccountWebService`, `CarrierOptionsWebService`, `Collection\Fulfilment\OrderCollection` and the carrier value objects: they work but should not attract new usage.

### Changed signatures on surviving classes

- `Model\Fulfilment\AbstractOrder::getDeliveryOptions()` returns `Model\Shipment\ShipmentOptions`; `setDeliveryOptions()` takes it. `getCarrierId()` / `setCarrierId()` are new, and **`getCarrier()` throws if the carrier id was never set**.
- `Model\MyParcelCustomsItem::setDescription($description, $carrier = null)` ignores `$carrier`; the maximum length is 50. `setClassification()` cuts an HS code to 10 characters, which the module cannot change.
- `Model\Carrier\AbstractCarrier` gains `TYPE_B2C` / `TYPE_B2B`, relocated from `AbstractConsignment`.
- `Helper\SplitStreet` throws a plain `\InvalidArgumentException`; `InvalidConsignmentException` is gone.

### Recorded divergences from `myparcelnl/pdk`

Four, all deliberate. A future reader finding them should treat them as decisions, not omissions.

1. **We reuse the SDK's `CapabilitiesMapper::mapToCoreApi()`** rather than porting PDK's private `hydrateModel()`. The mapper builds typed generated models through typed setters, so wire keys come from each model's own `attributeMap`. It also maps our option names to the V2 names (`signature → setRequiresSignature`, `only_recipient → setRecipientOnlyDelivery`, `age_check → setRequiresAgeVerification`, `receipt_code → setRequiresReceiptCode`, `large_format → setOversizedPackage`, `collect → setScheduledCollection`, `return → setReturnOnFirstFailedDelivery`, `printerless_return → setPrintReturnLabelAtDropOff`), and it normalises `null` to an empty object because `null` means "enabled, unconfigured" while `false` and `0` are meaningful.
2. **We do not port `filterSupportedCapabilities()`.** See [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md). A capability allow-list is the mechanism by which upstream additions break integrations.
3. **We do not port `InsuranceTierMath`.** See [FR-000009](../functional-requirements/FR-000009-insurance-as-a-range.md). The API accepts any amount in range.
4. **An option the package type cannot carry narrows the package type; the PDK drops the option.** `CapabilitiesOptionCalculator` forces any option the narrowed response omits to `DISABLED`, so a mailbox order simply loses its age check. For 18+ goods that is a compliance failure, so the module keeps the option and rules the package type out at checkout instead (`Checkout::checkPackageType()`, `ShipmentOption::LIMIT_PACKAGE_TYPE`).

### Prerequisite hand-off from #967

**Config path.** `myparcelnl_magento_general/account_settings_{sha256(apiKey)}`, assembled as `Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint->of($apiKey)`, written at **default scope only** whatever scope a legacy row sat at. Contract definitions are stored alongside it.

**Shared hash helper.** `MyParcelNL\Magento\Service\Hash\Fingerprint`, dependency-free: `of(string)` is sha256 as 64 lowercase hex, `isFingerprint(string)` tells a hashed value from a raw one, `LABEL_LENGTH` (12) is the prefix to log. The same helper keys the capabilities cache (TR-000007). Its output is a storage format: changing `of()` orphans every row keyed by it and invalidates the cache, so treat that as a data migration.

**Migration.** `src/Setup/Migrations/FingerprintAccountSettingsPaths.php`, idempotent, never overwrites an existing fingerprinted row.

### Module name to Core API v2 name

One map per kind, on the facade that owns the names:

| Kind | Map | Shape |
|---|---|---|
| Carrier | `Model\Shipment\Carrier::V2_NAMES_MAP` | `postnl` ⇄ `POSTNL`, `dhlforyou` ⇄ `DHL_FOR_YOU`, `upsstandard` ⇄ `UPS_STANDARD`, … |
| Package type | `Model\Shipment\PackageType::V2_NAMES_MAP` | `letter` ⇄ `UNFRANKED`, `package_small` ⇄ `SMALL_PACKAGE`, the rest upper-cased |
| Delivery type | `Model\Shipment\DeliveryType::V2_NAMES_MAP` | the name upper-cased plus `_DELIVERY` |
| Shipment option | `Model\Shipment\ShipmentOption::V2_KEYS_MAP` | `signature` ⇄ `requiresSignature`, `only_recipient` ⇄ `recipientOnlyDelivery`, … |

Each has a `toV2*()` and a `fromV2*()`; **`fromV2*()` returns null for a value the module does not know, and the caller logs it** rather than substituting. The package, delivery and carrier maps take their v2 values from the generated `RefShipmentPackageTypeV2`, `RefTypesDeliveryTypeV2` and `RefCapabilitiesSharedCarrierV2` enums. The option map is written out, because `CapabilitiesMapper::KNOWN_OPTION_SETTERS` is private; `Tests/Unit/Model/Shipment/V2NameMapTest.php` round-trips every entry through the SDK's own `mapToCoreApi()`, so every module option is sendable and the request and response sides cannot disagree on a wire key.

**The REST transformers keep their own maps.** `Model\Rest\Transformer\{Carrier,PackageType,DeliveryType}Transformer` bind to **Order API** enums while capabilities is **Core API**. The strings match today, but they are two generated contracts that can diverge. There is also a live difference: `CarrierTransformer::LEGACY_NAME_MAP` maps `ups` but not `upsstandard`, the name `Config::CARRIERS_XML_PATH_MAP` uses, so sharing the map would change a shipped versioned response. If they are ever merged, that difference needs its own test first.

### Money scales

Three coexist, and each mistake is a factor of 100 or 10 000 on a merchant's insured value.

| Scale | Where | Established by |
|---|---|---|
| Whole euros | Every stored setting, the frozen legacy tiers, `Adapter\DeliveryOptions\ShipmentOptions::getInsurance()` | The module's own domain |
| Cents | **Core API**: capabilities, contract definitions, shipment create | `Tests/Fixtures/capabilities-acceptance-v2.json` answers `max: 500000` for a €5000 bound |
| Integer-micro (1 EUR = 1 000 000) | The module's **own** REST v1 delivery-options response | `docs/openapi/delivery-options.yaml`, which records the divergence from the Order API's Money object as deliberate |

Conversion between the first two happens in one place, `Model\Shipment\Capabilities\InsuranceRange`. The third is `Model\Rest\Transformer\ShipmentOptionsTransformer`'s alone.

## Verification Method

Static verification, since this is a compatibility requirement.

### Test Scenarios

1. **No removed symbol remains.** Grep `src/`, `Controller/`, `view/` and `Tests/` for every removed FQCN; expect zero matches. Include `view/adminhtml/templates/new_shipment.phtml`, which a PHP-only grep misses.
2. **DI compilation.** `bin/magento setup:di:compile` succeeds, and neither `src/Block/Sales/OrderAction.php` nor `ShipmentAction.php` takes an SDK consignment as a constructor argument. This is the only check that catches those two, since they have no `di.xml` entry.
3. **Composer resolution.** `composer update myparcelnl/sdk` resolves the pinned tag with `setasign/fpdi` present as an explicit dependency, and the Pest suite passes on PHP 8.1 through 8.4.

### Monitoring

None ongoing. Satisfied once and enforced by CI.

## Assumptions

- The pin is `11.0.0-beta.33`. Later betas are ordinary maintenance. beta.33 adds `DHL_FREIGHT` to the carrier enum, which the module has no name for: logged and ignored, which is FR-000010 working as designed.
- PHP floor stays `^7.4 || ^8.0`; the pinned tag declares the same.
- `Model\Shipment\ShipmentOptions::setDeliveryType()` normalises a string enum name to an id. The module passes ints, so this never applies.
- `Helper\SplitStreet` and `Helper\ValidateStreet` survive in v11 and must not be moved into the module.

## Constraints

- No `vendor/**` file may be modified.
- `guzzlehttp/guzzle ^7.10` is required transitively by the generated client, a hard floor on older Magento patch releases.
