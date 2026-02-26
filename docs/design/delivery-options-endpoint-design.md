# Delivery Options REST Endpoint — Implementation Design

## Context

The PDK already has a versioned REST endpoint for delivery options (`GetDeliveryOptionsEndpoint`). We need to build the equivalent in the Magento module. The Magento module does **not** use the PDK, so we must reimplement the mapping logic natively as a Magento REST service contract.

This document analyzes what the PDK does, what Magento already has, and how the data transformations work.

**Parent BR:** [BR-000001](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)

---

## What Magento Stores Today

The `sales_order.myparcel_delivery_options` column holds raw JSON in the SDK's internal format:

```json
{
  "carrier": "postnl",
  "date": "2025-03-15",
  "deliveryType": "standard",
  "packageType": "package",
  "shipmentOptions": {
    "signature": true,
    "only_recipient": false,
    "insurance": 50,
    "large_format": false,
    "age_check": false,
    "return": false
  },
  "pickupLocation": null
}
```

Values are **lowercase strings** and **plain booleans/integers**.

## What the API Returns (Order Service API Format)

```json
{
  "carrier": "POSTNL",
  "packageType": "PACKAGE",
  "deliveryType": "STANDARD_DELIVERY",
  "shipmentOptions": {
    "requiresSignature": {},
    "insurance": { "amount": 50000000, "currency": "EUR" }
  },
  "date": "2025-03-15T00:00:00+01:00",
  "pickupLocation": null
}
```

---

## Data Transformations

### 1. Carrier Names: lowercase to SCREAMING_SNAKE_CASE

Magento stores SDK names, the API needs Order Service constants:

| Stored in Magento | API Response | Non-obvious? |
|---|---|---|
| `postnl` | `POSTNL` | |
| `bpost` | `BPOST` | |
| `dhlforyou` | `DHL_FOR_YOU` | Yes — word boundaries added |
| `dhlparcelconnect` | `DHL_PARCEL_CONNECT` | Yes |
| `dhleuroplus` | `DHL_EUROPLUS` | Yes |
| `ups` | `UPS_STANDARD` | Yes — name changes entirely |
| `bol.com` / `bol_com` | `BOL` | Yes — truncated |
| `cheap_cargo` | `CHEAP_CARGO` | |
| `dpd` | `DPD` | |
| `gls` | `GLS` | |
| `brt` | `BRT` | |
| `trunkrs` | `TRUNKRS` | |

**Implication:** Cannot just `strtoupper()` — need a hardcoded mapping table for non-obvious ones. The PDK tries: direct match, then SCREAMING_SNAKE_CASE conversion, then mapping table, then error.

### 2. Package Type: lowercase to SCREAMING_SNAKE_CASE with renames

| Stored | API Response | Note |
|---|---|---|
| `package` | `PACKAGE` | |
| `mailbox` | `MAILBOX` | |
| `letter` | `UNFRANKED` | **Renamed** — not just uppercased |
| `digital_stamp` | `DIGITAL_STAMP` | |
| `package_small` | `SMALL_PACKAGE` | **Reordered** — not just uppercased |

### 3. Delivery Type: add `_DELIVERY` suffix

| Stored | API Response |
|---|---|
| `standard` | `STANDARD_DELIVERY` |
| `morning` | `MORNING_DELIVERY` |
| `evening` | `EVENING_DELIVERY` |
| `pickup` | `PICKUP_DELIVERY` |
| `express` | `EXPRESS_DELIVERY` |

### 4. Shipment Options: The Biggest Transformation

Three major changes from internal to API format:

#### a) Boolean options become empty objects `{}`

The Order Service API uses the **presence of a key** to mean "enabled" — the value is an empty object `{}`, not `true`. If the option is disabled/false, the key is **omitted entirely**.

```
Magento: "signature": true      ->  API: "requiresSignature": {}
Magento: "signature": false     ->  API: (key omitted)
```

**Why?** This is ADR-0013 (empty object standard). It communicates "this option is set" without implying a boolean value. It's extensible — if `requiresSignature` ever needs sub-fields, the object can grow without breaking the contract.

#### b) Field names are renamed (not just camelCased)

| Magento field | API field | Change type |
|---|---|---|
| `age_check` | `requiresAgeVerification` | Renamed + prefix |
| `signature` | `requiresSignature` | Prefix added |
| `only_recipient` | `recipientOnlyDelivery` | Renamed |
| `large_format` | `oversizedPackage` | Renamed |
| `return` / `direct_return` | `printReturnLabelAtDropOff` | Renamed |
| `hide_sender` | `hideSender` | camelCased only |
| `priority_delivery` | `priorityDelivery` | camelCased only |
| `receipt_code` | `requiresReceiptCode` | Prefix added |
| `same_day_delivery` | `sameDayDelivery` | camelCased only |
| `saturday_delivery` | `saturdayDelivery` | camelCased only |
| `collect` | `scheduledCollection` | Renamed |

#### c) Special-format options

**Insurance** — stored as integer euros, returned as micro-currency:
```
Magento: "insurance": 50
API:     "insurance": { "amount": 50000000, "currency": "EUR" }
```
Formula: `amount = value x 1,000,000`. Currency is hardcoded `EUR` for now.

**Label description** — stored as string, wrapped in object:
```
Magento: "label_description": "Fragile"
API:     "customLabelText": { "text": "Fragile" }
```

**Tracked** — inverted logic:
```
Magento: "tracked": false  ->  API: "noTracking": {}
Magento: "tracked": true   ->  API: (key omitted — tracking is the default)
```

### 5. TriState vs Boolean (Magento simplification)

The PDK uses TriState (-1 = inherit, 0 = disabled, 1 = enabled). Magento stores plain `true`/`false`. This means:

- **No INHERIT filtering needed** — Magento has already resolved inheritance at storage time
- We just filter: include the key if `true`, omit if `false`
- This makes our mapper simpler than the PDK's
- **Important**: SDK adapter boolean methods return `?bool` (nullable). Must check `=== true`, not just truthy.

### 6. Date: string to ISO 8601 with timezone

Magento stores `"2025-03-15"` (date only). The API returns `"2025-03-15T00:00:00+01:00"` (full ISO 8601 with timezone). If date is missing: return `null`. Timezone is `Europe/Amsterdam` (handles CET/CEST automatically).

### 7. Pickup Location: structured object

When `deliveryType` is `PICKUP_DELIVERY`, the response includes:

```json
{
  "pickupLocation": {
    "locationCode": "ABC123",
    "locationName": "PostNL Punt",
    "retailNetworkId": "PNPNL-01",
    "type": null,
    "address": {
      "street": "Hoofdstraat",
      "number": "1",
      "numberSuffix": null,
      "postalCode": "1234AB",
      "boxNumber": null,
      "city": "Amsterdam",
      "cc": "NL",
      "state": null,
      "region": null
    }
  }
}
```

Fields like `type`, `numberSuffix`, `boxNumber`, `state`, `region` are not stored in the SDK adapter — returned as `null`.

---

## Architecture

### Request Flow

```
GET /V1/myparcel/delivery-options?orderId=123
        |
        v
+-----------------------------+
|  webapi.xml route           |  Magento routes to service contract
+-------------+---------------+
              v
+-----------------------------+
|  OrderDeliveryOptions       |  Service implementation
|  (extends AbstractEndpoint) |
|  - Detect API version       |  from Accept/Content-Type headers
|  - Validate orderId         |  -> 400 if missing
|  - Load order               |  -> 404 if not found
|  - Read JSON column         |
|  - Delegate to V1Request    |
+-------------+---------------+
              v
+-----------------------------+
|  OrderDeliveryOptionsV1Req  |  V1 formatter
|  - CarrierTransformer       |  postnl -> POSTNL
|  - PackageTypeTransformer   |  letter -> UNFRANKED
|  - DeliveryTypeTransformer  |  standard -> STANDARD_DELIVERY
|  - ShipmentOptTransformer   |  true -> {}, insurance -> micro
|  - DateTransformer          |  ISO 8601
|  - PickupLocationTransformer|
+-------------+---------------+
              v
      JSON response with version headers
```

### File Structure

```
src/Api/
  OrderDeliveryOptionsInterface.php          # Service contract

src/Model/Rest/
  AbstractEndpoint.php                       # Version detection + error helpers
  AbstractVersionedRequest.php               # Base for version formatters
  ProblemDetails.php                         # RFC 9457 value object
  OrderDeliveryOptions.php                   # Main endpoint implementation

src/Model/Rest/Request/
  OrderDeliveryOptionsV1Request.php          # V1 formatter

src/Model/Rest/Transformer/
  CarrierTransformer.php                     # lowercase -> SCREAMING_SNAKE_CASE
  PackageTypeTransformer.php                 # SDK name -> API name
  DeliveryTypeTransformer.php                # SDK name -> API name
  ShipmentOptionsTransformer.php             # bool -> {}, insurance -> micro
  DateTransformer.php                        # string -> ISO 8601
  PickupLocationTransformer.php              # SDK -> structured object
```

### Error Handling (RFC 9457)

All errors return Problem Details format with `Content-Type: application/problem+json`:

| Status | Title | Detail |
|--------|-------|--------|
| 400 | Invalid Request | `Request validation failed: orderId` |
| 404 | Order Not Found | `Order with id {id} was not found` |
| 406 | Unsupported API Version | `API version {v} is not supported. Supported versions: 1` |
| 500 | Internal Server Error | `An unexpected error occurred` (no internal details leaked) |

Format: `{ "type": null, "status": N, "title": "...", "detail": "..." }`

---

## Verified SDK Dependencies

All adapter methods confirmed in installed SDK (v10.4+):

| Class | Methods Used | Return Types |
|---|---|---|
| `AbstractDeliveryOptionsAdapter` | `getCarrier()`, `getPackageType()`, `getDeliveryType()`, `getShipmentOptions()`, `getDate()`, `getPickupLocation()` | `?string`, `?string`, `?string`, `?AbstractShipmentOptionsAdapter`, `?string`, `?AbstractPickupLocationAdapter` |
| `AbstractShipmentOptionsAdapter` | `hasAgeCheck()`, `hasSignature()`, `hasOnlyRecipient()`, `hasLargeFormat()`, `isReturn()`, `hasHideSender()`, `isPriorityDelivery()`, `hasReceiptCode()`, `isSameDayDelivery()`, `hasCollect()`, `getInsurance()`, `getLabelDescription()` | All `?bool` except `getInsurance(): ?int`, `getLabelDescription(): ?string` |
| `AbstractPickupLocationAdapter` | `getLocationCode()`, `getLocationName()`, `getRetailNetworkId()`, `getStreet()`, `getNumber()`, `getPostalCode()`, `getCity()`, `getCountry()` | `string` (except `getCountry(): ?string`) |
| `DeliveryOptionsAdapterFactory` | `create(array $data)` static | Returns `AbstractDeliveryOptionsAdapter` |
| `Logger` (facade) | `error(string $message)` | Verified at `src/Facade/Logger.php` |

---

## Key Design Notes

- **`string` return type from service contract**: Bypasses Magento's serializer. Gives us full control over JSON output and HTTP status codes. Status set via `RestResponse::setHttpResponseCode()`.
- **`new \stdClass()` for boolean options**: `json_encode(new \stdClass())` produces `{}`. Simplest way to achieve empty-object format per ADR-0013.
- **No delivery options = success with nulls**: Orders can exist without MyParcel delivery options (manual orders, non-MyParcel shipping). Returning 404 would be misleading.
- **`=== true` checks**: SDK adapter booleans return `?bool`. Must strict-compare to avoid treating `null` as falsy-but-present.
- **Separate transformer classes**: Single responsibility, independently testable, reusable across future API versions. DI-injectable and replaceable via `di.xml` virtual types.

## Potential Challenges

1. **Double-encoded JSON**: When service contract returns `string`, Magento may wrap it in quotes. If this happens, set `RestResponse` body directly.
2. **Query param binding**: If `orderId` doesn't auto-bind from query string, fallback is route param: `/V1/myparcel/delivery-options/:orderId`.
3. **DST edge case**: `Europe/Amsterdam` is `+01:00` (CET) or `+02:00` (CEST). `DateTimeImmutable` handles this correctly.

---

## PDK Reference Files

| What | PDK File |
|------|----------|
| Endpoint handler | `src/App/Endpoint/Handler/GetDeliveryOptionsEndpoint.php` |
| Version detection | `src/App/Endpoint/Contract/AbstractEndpoint.php` |
| V1 Request validation | `src/App/Endpoint/Request/GetDeliveryOptionsV1Request.php` |
| V1 Response mapping | `src/App/Endpoint/Resource/DeliveryOptionsV1Resource.php` |
| Error responses | `src/App/Endpoint/Contract/AbstractVersionedRequest.php` |
| RFC 9457 model | `src/App/Endpoint/ProblemDetails.php` |
| Carrier constants | `src/Carrier/Model/Carrier.php` |
