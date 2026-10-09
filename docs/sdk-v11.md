# SDK v11

What the code cannot say about itself. The reasoning behind the migration is in the classes it
produced and in the tests that pin them; this file holds the facts that live outside the module.

The first two sections are ADR-shaped and belong in
[`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr). Raise
them there when you have access.

## Why the module owns its shipment domain layer

The module was pinned at `myparcelnl/sdk` `11.0.0-beta.15` because **beta.22 deleted the legacy
consignment stack** it was built on. No later SDK could be installed: no bug fixes, no regenerated
clients, no new carriers or options. The pin is now `11.0.0-beta.36`, and the vocabulary maps under
`src/Model/Shipment/` are the module's own: the consignment classes that held package types,
delivery types and option names are gone, and there are none to adopt.

**Carriers are the exception.** `MyParcelNL\Sdk\Model\Carrier\` survived beta.22, so
`Model\Shipment\Carrier` takes each name from the SDK class that owns it
(`CarrierPostNL::NAME`) and its label from `CarrierFactory`, rather than repeating either. The
module keeps no carrier list: a module name derives from the v2 name, and `Carrier::toV2Name()` and
`Carrier::idFor()` read the way back from `ApiMapperService::forCarrier()`. A carrier the SDK knows
is exportable, and an account's real set comes from capabilities. The local country that picks the
street split rule is the account's home country, from `Model\Settings\Proposition`.

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

**When capabilities cannot be had, the module serves the last answer, else everything.**
`Capabilities\Repository` answers a permissive set for a store with no API key and for a request that
will not serialise. Four more cases serve the shape's last good answer from `Capabilities\StoredAnswers`
(Magento's `flag` table, so it survives `cache:clean` and a Redis restart), and a permissive set only
when the shape was never answered: a shape that failed within the last 60 seconds, a failed fetch,
a cache or lock backend that throws, and a shape another request is already fetching. The last one
is a lock taken with a zero wait, not a queue — after `cache:clean` every concurrent render misses
the same shape at once, and making them wait for one call is the cost being avoided, not the fix. `AccountSettings\Maintenance` deletes the
stored answers of an API key that is configured nowhere any more.

A cached answer lives three hours. Then the next lookup of that shape fetches again, and a failed
refresh serves the stored answer. That is the longest a capabilities change, a bugfix included, takes
to reach a shop. To get it sooner, flush the "MyParcel carrier capabilities" cache type.

`Capabilities\Client` gives up on an unreachable host after two seconds and on a silent one after
ten. They differ because an unreachable host is the failure a waiting customer pays for. What a
render pays is one connect timeout per *distinct* shape it asks about: `ShapeLookup` memoises per
store, country, package type and carrier, and the Repository memoises per request shape on top of
that.

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
4. **An option the package type cannot carry also narrows the package type at checkout.** The
   export drops an option the carrier does not offer for the shipment, as the PDK does
   (`ShipmentOptionsResolver::dropNotOffered()`), so the API never refuses a shipment for it. The
   checkout first rules out a package type that cannot carry a forced option
   (`Checkout::checkPackageType()`, `ShipmentOption::LIMIT_PACKAGE_TYPE`), so an 18+ order keeps its
   age check where a package type can carry it. An option dropped at export is logged.

## Vocabulary per boundary

Four vocabularies for the same five facts. Hand each boundary the one it expects.

| Boundary | Vocabulary | Established by |
|---|---|---|
| Shipment create, the outbound path | **integer id** | `ShipmentOptions::openAPITypes()` forces `package_type` to `int`; `setPackageType()` / `setDeliveryType()` coerce a string to an id before storing |
| Capabilities request and response | **v2 enum name** (`SMALL_PACKAGE`, `STANDARD_DELIVERY`) | `CapabilitiesRequest::withPackageType()`; the response's `getPackageTypes()` |
| Versioned REST v1 endpoint | Order API enum name | `Model\Rest\Transformer\PackageTypeTransformer` |
| `core_config_data`, the order's delivery-options JSON, the checkout widget | **module snake_case** | Persisted data; cannot change without a migration |

One map per kind, on the facade that owns the names: `PackageType::V2_NAMES_MAP`,
`DeliveryType::V2_NAMES_MAP`, `ShipmentOption::V2_NAMES_MAP`. `Carrier` has no map and reads the
SDK's carrier table instead. Each has a `toV2*()` and a `fromV2*()`. **`PackageType::fromV2Name()` and `DeliveryType::fromV2Name()`
return null for a value the module does not know, and the caller logs it** rather than substituting
one. `Carrier` and `ShipmentOption` derive a name for every value (INT-1289), so a carrier is
reported only when the SDK does not know it, and an option is never reported.

`Tests/Unit/Model/Shipment/V2NameMapTest.php` round-trips every option entry through the SDK's own
`mapToCoreApi()`, because `CapabilitiesMapper::KNOWN_OPTION_SETTERS` is private and the map is
therefore written out by hand.

**The REST transformers keep their own maps.** `Model\Rest\Transformer\{Carrier,PackageType,
DeliveryType}Transformer` bind to **Order API** enums while capabilities is **Core API**. The strings
match today, but they are two generated contracts that can diverge. There is also a live difference:
`CarrierTransformer::LEGACY_NAME_MAP` maps `ups` but not `upsstandard`, the name the settings
paths use, so sharing the map would change a shipped versioned response.
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
- The order grid's PPS export honours the carrier and package type saved in the options modal.
- An order without a delivery date no longer ships with tomorrow as its date, and a malformed date
  no longer stops the export. DPD, bpost and a collect shipment get no delivery date: the API
  refuses one there.
- The order grid's "Delivery date" column showed the drop-off day; it is now labelled so.

**New**

- Insurance is any amount within the contract range. The settings screen validates per carrier
  against contract definitions; the export clamps per shipment against capabilities for the real
  destination and package type. GLS gains a Belgium insurance field.
- Export runs in chunks of a configurable size, default 20. Each chunk is recorded before the next is
  sent, a rejected chunk is re-sent once without the orders the API named, and failures are reported
  per order with the API's own sentence and field.
- The export no longer navigates: the grid keeps its selection and reloads once the labels exist.
- The track & trace link comes from the API. The fallback host follows the account's proposition.
- A dedicated capabilities cache type, enabled once on upgrade when `env.php` does not mention it.
- A shipment options modal on the order grid, the shipment grid, the order and shipment views and
  the New Shipment page. It saves carrier, package type, options, insurance, label amount,
  digital stamp weight, the delivery date (or its removal) and the package dimensions on the order,
  so a refused export can be corrected and exported again.
- Why the last export of an order failed stays on the order, in the grid's "MyParcel export error"
  column and on the order view, until an export of that order succeeds.

## Open risks

- **Capability parity** is the least certain part. Where PDK and the OpenAPI spec disagree, an
  observed acceptance response wins.
- **The loose-coupling trade** holds only while the API's error reaches the admin legibly. Anything
  that swallows or flattens a rejection breaks it. **The log is the deliberate exception**:
  `Rejection::shapeOf()` records the body's keys and field pointers and never its values, because
  the API names the field it refused in text that quotes what was sent — on an address, the
  consumer's own data. The admin's per-order message still comes from `Rejection::reasons()` and is
  unchanged.
- **The SDK defects above** leave workaround code in place until they land.
- **The REST transformers** bind to the generated Order API enums, the highest-churn SDK surface.
  `ShipmentOptionsTransformerTest` asserts the `attributeMap()` keys verbatim.
- **The PPS status poll asks for every order the account has.** `OrderCollection::query()` is given
  no parameters, so the API answers with its own default page and the increment ids are matched in
  PHP afterwards — an order past that page never matches, and the call is made once per API key per
  tick. `query()` does accept a parameters array, but neither the SDK nor the published Order API
  spec names a filter for the external identifier, so the parameter name has to come from the API
  team before this can be narrowed. Defect 6 covers the missing user agent on the same call.
- **`MultiColloShipmentService` takes no API key** while every other export service does.
- **`extra_assurance`** stays in `Adapter\DeliveryOptions\ShipmentOptions`'s key list with no reader:
  the key order is a persisted format, so removing it is a data question, not a code question.
