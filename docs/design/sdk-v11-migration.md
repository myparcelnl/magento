# SDK v11 migration

**Status:** complete, PR #966
**Branch:** `feat/use-sdk-v11-shipments`
**Business Requirement:** [BR-000003](../business-requirements/BR-000003-sdk-v11-compatibility.md)

This is the record of what the migration changed and why. Decisions a maintainer needs are
stated at the code they govern, not here.

---

## Why

The module pinned `myparcelnl/sdk` at `11.0.0-beta.15`. beta.22 deleted the legacy consignment
stack the module was built on, so no later SDK could be installed: no bug fixes, no regenerated
clients, no new carriers or options. Two more things went with that stack:

- **Multi-account batch export.** `MyParcelCollection` grouped consignments by API key and split
  the API calls per account. Nothing in v11 does that; the key is a constructor argument on each
  service. The module now owns the grouping (FR-000007, TR-000006).
- **Capability probes.** The admin form and the checkout asked a throwaway consignment which package
  types, options and insurance tiers exist. Those answers were the same for every merchant and are
  gone. They now come from the capabilities and contract-definitions endpoints, per account
  (FR-000008, TR-000007), and the module keeps working when that data is missing or unknown
  (FR-000010).

The pin is now `11.0.0-beta.33`. `myparcelnl/pdk` made the same migration first and is the
reference where the SDK is silent; the four places this module deliberately diverges from it are
listed in TR-000005.

---

## What the module now owns

- `src/Model/Shipment/` — `PackageType`, `DeliveryType`, `ShipmentOption`, `CountryCode`, `Carrier`:
  the module's names, their ids, and the maps to the API's v2 vocabulary. `Type/` holds a stored
  type the module does not recognise without substituting one. `Capabilities/` is the client,
  the cache-backed repository, `CapabilitySet` and `InsuranceRange`. `ShipmentBuilder`,
  `FulfilmentOrderBuilder` and the `OrderShipmentOptions` they share turn a Magento order into a
  v11 shipment or a PPS order. `CustomsDeclarationBuilder` and `ShipmentValidator` serve the
  shipment path only.
- `src/Adapter/DeliveryOptions/` — `DeliveryOptions`, `ShipmentOptions`, `PickupLocation` and a
  factory that detects which stored shape an order carries.
- `src/Service/Export/` — `ShipmentExportService` (grouping by key, chunking, one retry without the
  orders the API blamed, per-order attribution), `ShipmentApiProvider` (the one place a client is
  built from a key), `ShipmentQuery`, `LabelPdfMerger`, `LabelHttpClient`, `ExportResponse`.
- `src/Service/TrackTrace/` — `LinkResolver` and `AccountPlatform`; `Service\TrackTraceUrl` is the
  fallback for shipments stored before the link came from the API.
- `src/Service/AccountSettings/` — contract definitions stored with the account settings;
  `Service\UserAgent`; `Service\Hash\Fingerprint`, shared with the config path since #967.

Three rules run through all of it and are specified in the FRs rather than repeated here: the API
is the validator and capabilities only inform what is offered (FR-000010); a stored value the
module does not recognise is kept and sent, or fails its own shipment naming the order, and is never
replaced (FR-000010); carrier and country facts are tested against a stubbed capabilities response,
never pinned as module truth (FR-000008).

---

## What changed for merchants

**Removed**

- Pre-export address validation. The API is the only authority; the "please check street" and
  "please check postal code" grid warnings are gone (BR-000003).
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

---

## SDK defects raised, and where the module works around them

| # | Defect | Workaround, delete when fixed |
|---|---|---|
| 1 | `HttpCapabilitiesClient` calls `postCapabilities()` with the pre-beta.25 argument order | `Capabilities\Client` posts the request itself |
| 2 | The capabilities client accepts no API key, and `ShipmentApiFactory`, `WebhookApiFactory`, `IamApiFactory` substitute `getenv('API_KEY')` for an empty key | Same client; `ShipmentApiProvider` raises before any factory sees an empty key (TR-000006) |
| 3 | `CapabilitiesMapper::mapFromCoreApi()` keeps only option keys, dropping insurance bounds | The module reads the decoded body into its own value objects |
| 4 | `ObjectSerializer` percent-encodes the `;` separator in the label path and the positions query, and the API answers 500 | `Service\Export\LabelHttpClient` |
| 5 | `parseCreateResponse()` drops `secondary_shipments`, so a creator never learns the colli ids | `updateMagentoTrack()` reads them from the query response |
| 6 | `Collection\Fulfilment\OrderCollection::query()` is static and sends no user agent | None possible; the PPS status poll is the one unidentified call |
| 7 | `MyParcelCustomsItem::setClassification()` cuts an HS code to 10 characters | None; affects the PPS path only |

Two generation traps worth knowing: `RefShipmentCustomsDeclarationItem::setCountry()` accepts only
`''`, so the country goes in through the constructor and `ShipmentValidator` skips customs; and
`ShipmentDefsShipment::getParentId()` returns a property-less stub, so colli are paired by nesting,
never by id.

---

## Verification

The local SDK is a symlink to `app/code/MyParcelNL/Sdk`; switch versions with
`git -C app/code/MyParcelNL/Sdk checkout v11.0.0-beta.33`.

```bash
# module directory; CI runs this on PHP 8.1–8.4
composer install --no-interaction
vendor/bin/pest

# Magento root
php -dmemory_limit=-1 bin/magento setup:upgrade
php -dmemory_limit=-1 bin/magento setup:di:compile
php -dmemory_limit=-1 bin/magento setup:static-content:deploy
php -dmemory_limit=-1 bin/magento cache:clean
```

Manual, on `*.acceptance.myparcel.nl` credentials only:

1. **Single store.** A shipment from the order view: concept, barcode, track & trace link, label.
2. **An 18+ order light enough for a mailbox.** Checkout offers and prices `package`, not mailbox.
   With the attribute removed and the carrier default off, mailbox returns. With two carriers where
   only one carries an age check on a mailbox, the tolerant one keeps mailbox and the other is not
   offered. The New Shipment form pre-checks the age check; unticking it exports without one.
3. **Two stores, two API keys.** Orders from both in one mass action: each shipment lands in its own
   account and one merged PDF downloads.
4. **Chunking.** About 50 orders at the default size: three calls, all labels. Then size 1 and 100.
   Then one bad address in chunk 3: chunks 1 and 2 stay recorded, the bad order is named with the
   API's sentence, the rest of its chunk ships on the retry.
5. **Package types** mailbox, digital stamp, small package, letter.
6. **Pickup**, including a carrier override in the modal clearing an inherited pickup location, and a
   pickup whose stored location is damaged being refused by name rather than shipped as home delivery.
7. **ROW destination** with customs items: one item per shipped item, descriptions cut at 50 with an
   ellipsis, HS codes up to 18 characters on the shipment path.
8. **Multicollo** PostNL NL/BE package, and return-in-the-box: every collo gets a track and barcode,
   the return description carries the date.
9. **PPS mode and the `UpdateStatus` cron across two accounts.** One order per store in one mass
   action: each lands in its own account with only its own order lines. The cron gives both a
   barcode, status and link. Break one store's key: the other still updates and the failure is one
   log warning. A PPS pickup stored as mailbox ships as mailbox. A DPD order to a Belgian address
   splits its street by Belgian rules. The modal's carrier reaches the PPS export.
10. **A store with no API key** produces the module's `LocalizedException`, never an env-var
    fallback.
11. **Malformed NL postcode and street.** The order is accepted with no grid warning; export shows
    the API's rejection naming the order and the field.
12. **Store-scoped package settings.** Two stores with different mailbox and digital stamp settings
    each resolve their own at checkout.
13. **`early_morning` or `same_day` order.** It ships as that delivery type.
14. **Receipt code default on**, NL evening order: no receipt code, signature and only-recipient kept.
15. **Label description with `%delivery_date%`.** The date renders.
16. **Belgium with UPS Standard** and an 18+ product: the form offers and pre-checks the age check,
    the shipment carries it. PostNL to Belgium offers no age check box.

---

## Open risks

- **Capability parity** is the least certain part. Where PDK and the OpenAPI spec disagree, an
  observed acceptance response wins. Raise gaps as questions and record the working assumption in
  TR-000005; do not block on an upstream answer.
- **The loose-coupling trade** in FR-000010 holds only while the API's error reaches the admin
  legibly. Anything that swallows or flattens a rejection breaks it.
- **The SDK defects above** leave workaround code in place until they land. Defect 1 breaks
  capabilities for every SDK consumer on beta.25 and later, so other integrations will hit it.
- **The REST transformers** bind to the generated Order API enums, the highest-churn SDK surface.
  `ShipmentOptionsTransformerTest` asserts the `attributeMap()` keys verbatim.
- **`MultiColloShipmentService` takes no API key** while every other export service does. Rule in
  TR-000006.
- **`extra_assurance`** stays in `Adapter\DeliveryOptions\ShipmentOptions::toArray()` with no
  reader: the key order is a persisted format, so removing it is a data question.
- An ADR for "the Magento module owns its shipment domain layer" is worth raising in
  [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr).
