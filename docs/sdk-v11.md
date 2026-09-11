# SDK v11

What the code cannot say about itself. The reasoning behind the migration is in the classes it
produced and in the tests that pin them; this file holds the facts that live outside the module.

The first two sections are ADR-shaped and belong in
[`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr). Raise
them there when you have access.

## Why the module owns its shipment domain layer

The module was pinned at `myparcelnl/sdk` `11.0.0-beta.15` because **beta.22 deleted the legacy
consignment stack** it was built on. No later SDK could be installed: no bug fixes, no regenerated
clients, no new carriers or options. The pin is now `11.0.0-beta.33`, and the vocabulary maps under
`src/Model/Shipment/` are the module's own: the consignment classes that held package types,
delivery types and option names are gone, and there are none to adopt.

**Carriers are the exception.** `MyParcelNL\Sdk\Model\Carrier\` survived beta.22, so
`Model\Shipment\Carrier` takes each name from the SDK class that owns it
(`CarrierPostNL::NAME`) and its label from `CarrierFactory`, rather than repeating either. What
stays the module's own is *which* carriers it supports — the eight it has admin settings and
insurance virtual types for, against the SDK's thirteen — and the two maps the SDK has no
equivalent of, `V2_NAMES_MAP` and `LOCAL_COUNTRY_MAP`. An account's real set comes from
capabilities, never from either list.

Two capabilities went with the deleted stack and are module code now:

- **Multi-account batch export.** `MyParcelCollection` grouped consignments by API key and split the
  calls per account. In v11 the key is a constructor argument on each service, so the module groups
  (`Service\Export\ShipmentExportService`, `ShipmentApiProvider`, `LabelPdfMerger`).
- **Capability probes.** The admin form and the checkout asked a throwaway consignment which package
  types, options and insurance tiers exist. Those answers were the same for every merchant. They now
  come from the capabilities and contract-definitions endpoints, per account
  (`Model\Shipment\Capabilities\`).

`myparcelnl/pdk` made the same migration first and is the reference where the SDK is silent.

Three rules run through the result:

1. **The API is the validator.** Capabilities inform what the UI offers. They never gate an export.
2. **A stored value the module does not recognise is kept and sent**, or fails its own shipment
   naming the order. It is never replaced with a value the module does recognise.
3. **Carrier and country facts are tested against a stubbed capabilities response**, never pinned as
   module truth.

## Deliberate divergences from `myparcelnl/pdk`

Four, all deliberate. A reader who finds them should treat them as decisions, not omissions.

1. **The module reuses the SDK's `CapabilitiesMapper::mapToCoreApi()`** rather than porting PDK's
   private `hydrateModel()`. The mapper builds typed generated models through typed setters, so wire
   keys come from each model's own `attributeMap`. It also maps module option names to the V2 names
   (`signature → setRequiresSignature`, `only_recipient → setRecipientOnlyDelivery`,
   `age_check → setRequiresAgeVerification`, `receipt_code → setRequiresReceiptCode`,
   `large_format → setOversizedPackage`, `collect → setScheduledCollection`,
   `return → setReturnOnFirstFailedDelivery`, `printerless_return → setPrintReturnLabelAtDropOff`),
   and it normalises `null` to an empty object, because `null` means "enabled, unconfigured" while
   `false` and `0` are meaningful.
2. **`filterSupportedCapabilities()` is not ported.** A capability allow-list is the mechanism by
   which upstream additions break integrations.
3. **`InsuranceTierMath` is not ported.** The API accepts any amount in range.
4. **An option the package type cannot carry narrows the package type; the PDK drops the option.**
   `CapabilitiesOptionCalculator` forces any option the narrowed response omits to `DISABLED`, so a
   mailbox order simply loses its age check. For 18+ goods that is a compliance failure, so the
   module keeps the option and rules the package type out at checkout instead
   (`Checkout::checkPackageType()`, `ShipmentOption::LIMIT_PACKAGE_TYPE`).

## Vocabulary per boundary

Four vocabularies for the same five facts. Hand each boundary the one it expects.

| Boundary | Vocabulary | Established by |
|---|---|---|
| Shipment create, the outbound path | **integer id** | `ShipmentOptions::openAPITypes()` forces `package_type` to `int`; `setPackageType()` / `setDeliveryType()` coerce a string to an id before storing |
| Capabilities request and response | **v2 enum name** (`SMALL_PACKAGE`, `STANDARD_DELIVERY`) | `CapabilitiesRequest::withPackageType()`; the response's `getPackageTypes()` |
| Versioned REST v1 endpoint | Order API enum name | `Model\Rest\Transformer\PackageTypeTransformer` |
| `core_config_data`, the order's delivery-options JSON, the checkout widget | **module snake_case** | Persisted data; cannot change without a migration |

One map per kind, on the facade that owns the names. `Carrier::V2_NAMES_MAP` is keyed by the SDK's
own carrier names; the map itself is the module's. `Carrier::V2_NAMES_MAP`,
`PackageType::V2_NAMES_MAP`, `DeliveryType::V2_NAMES_MAP`, `ShipmentOption::V2_NAMES_MAP`. Each has
a `toV2*()` and a `fromV2*()`. **`fromV2*()` returns null for a value the module does not know, and
the caller logs it** rather than substituting one.

`Tests/Unit/Model/Shipment/V2NameMapTest.php` round-trips every option entry through the SDK's own
`mapToCoreApi()`, because `CapabilitiesMapper::KNOWN_OPTION_SETTERS` is private and the map is
therefore written out by hand.

**The REST transformers keep their own maps.** `Model\Rest\Transformer\{Carrier,PackageType,
DeliveryType}Transformer` bind to **Order API** enums while capabilities is **Core API**. The strings
match today, but they are two generated contracts that can diverge. There is also a live difference:
`CarrierTransformer::LEGACY_NAME_MAP` maps `ups` but not `upsstandard`, the name
`Config::CARRIERS_XML_PATH_MAP` uses, so sharing the map would change a shipped versioned response.
Merging them needs a test for that difference first.

## Money scales

Three coexist, and each mistake is a factor of 100 or 10,000 on a merchant's insured value.

| Scale | Where | Established by |
|---|---|---|
| Whole euros | Every stored setting, the frozen legacy tiers, `Adapter\DeliveryOptions\ShipmentOptions::getInsurance()` | The module's own domain |
| Cents | **Core API**: capabilities, contract definitions, shipment create | `Tests/Fixtures/capabilities-acceptance-v2.json` answers `max: 500000` for a €5000 bound |
| Integer-micro (1 EUR = 1 000 000) | The module's **own** REST v1 delivery-options response | `docs/openapi/delivery-options.yaml`, which records the divergence from the Order API's Money object as deliberate |

Conversion between the first two happens in one place, `Model\Shipment\Capabilities\InsuranceRange`.
The third is `Model\Rest\Transformer\ShipmentOptionsTransformer`'s alone.

## SDK defects and their workarounds

Each workaround names what to delete once the fix lands.

| # | Defect | Workaround, delete when fixed |
|---|---|---|
| 1 | `HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | `Capabilities\Client` posts the request itself |
| 2 | The capabilities client accepts no API key, and `ShipmentApiFactory`, `WebhookApiFactory`, `IamApiFactory` substitute `getenv('API_KEY')` for an empty key | Same client; `ShipmentApiProvider` raises before any factory sees an empty key |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` keeps only option keys, dropping insurance bounds | The module reads the decoded body into its own value objects |
| 4 | `ObjectSerializer` percent-encodes the `;` separator in the label path and the positions query, and the API answers 500 | `Service\Export\LabelHttpClient` |
| 5 | `parseCreateResponse()` drops `secondary_shipments`, so a creator never learns the colli ids | `updateMagentoTrack()` reads them from the query response |
| 6 | `Collection\Fulfilment\OrderCollection::query()` is static and sends no user agent | None possible; the PPS status poll is the one unidentified call |
| 7 | `MyParcelCustomsItem::setClassification()` cuts an HS code to 10 characters | None; affects the PPS path only |

Defect 1 breaks capabilities for every SDK consumer on beta.25 and later, so other integrations will
hit it.

Two generation traps worth knowing: `RefShipmentCustomsDeclarationItem::setCountry()` accepts only
`''`, so the country goes in through the constructor and `ShipmentValidator` skips customs; and
`ShipmentDefsShipment::getParentId()` returns a property-less stub, so colli are paired by nesting,
never by id.

## What changed for merchants

**Removed**

- Pre-export address validation. `ValidatePostalCode` is deleted in the SDK and `ValidateStreet`
  survives; half a check is worse than none, so the API is the only authority. The "please check
  street" and "please check postal code" grid warnings are gone, and a malformed address no longer
  drops its order out of a mass action before export.
- The per-country print-position rule on the order and shipment views. It answered the same for
  every order, and the grid never used it; positions are offered everywhere.
- The rule that forced a PPS pickup to package type `package`. A pickup ships as the type stored.

**Corrected**

- The EU country list gains Malta and loses Kosovo, moving both between the EU and ROW branches for
  customs and insurance.
- `early_morning` and `same_day` ship as chosen. They used to ship, and be charged, as standard.
- Receipt code applies to standard delivery only. Evening and morning orders keep signature and
  only-recipient instead of losing them.
- The label description uses all 45 characters, is visibly shortened when cut, and the return label
  reads `Retour <description> t/m <date>` so the date always fits.
- The HS code is stored as text of up to 18 characters. It was an INT column that clamped long codes.
- Age check: the product attribute and carrier default tiers work, the New Shipment form pre-checks
  the box from the same answer the export uses, and there is no NL-only gate.
- A PPS order's barcode, MyParcel shipment id, status and track & trace link reach its Magento track,
  and the status cron recovers rows an earlier run left stamped with a placeholder.
- Every collo of a multicollo gets its own track and barcode, in both export modes.
- Return labels and status polling use each order's own account, not the first order's.
- The order grid's PPS export honours the modal's carrier and package type.

**New**

- Insurance is any amount within the contract range. The settings screen validates per carrier
  against contract definitions; the export clamps per shipment against capabilities for the real
  destination and package type. GLS gains a Belgium insurance field.
- Export runs in chunks of a configurable size, default 20. Each chunk is recorded before the next is
  sent, a rejected chunk is re-sent once without the orders the API named, and failures are reported
  per order with the API's own sentence and field.
- The export no longer navigates: the grid keeps its selection and reloads once the labels exist.
- The track & trace link comes from the API. The fallback host follows the account's platform.
- A dedicated capabilities cache type, enabled once on upgrade when `env.php` does not mention it.

## Open risks

- **Capability parity** is the least certain part. Where PDK and the OpenAPI spec disagree, an
  observed acceptance response wins.
- **The loose-coupling trade** holds only while the API's error reaches the admin legibly. Anything
  that swallows or flattens a rejection breaks it.
- **The SDK defects above** leave workaround code in place until they land.
- **The REST transformers** bind to the generated Order API enums, the highest-churn SDK surface.
  `ShipmentOptionsTransformerTest` asserts the `attributeMap()` keys verbatim.
- **`MultiColloShipmentService` takes no API key** while every other export service does.
- **`extra_assurance`** stays in `Adapter\DeliveryOptions\ShipmentOptions`'s key list with no reader:
  the key order is a persisted format, so removing it is a data question, not a code question.
