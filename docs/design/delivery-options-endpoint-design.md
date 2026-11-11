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
| `upsexpresssaver` | `UPS_EXPRESS_SAVER` | Yes — word boundaries added |
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

### Version Negotiation (ADR-0011)

ADR-0011 mandates that versioning uses **only** `Content-Type` and `Accept` headers — no custom headers, no path-based versioning. This has two critical implications:

1. **No custom signal headers** — endpoint-to-plugin communication (e.g. telling the response plugin which version was negotiated) must NOT use `X-MyParcel-*` or similar headers. Use a shared DI service (`VersionContext`) instead.
2. **Response headers** — every versioned response MUST include:
   - `Content-Type: application/json; version={N}; charset=utf-8` — the negotiated version
   - `Accept: application/json; version=1, application/json; version=2` — all supported versions (ADR-0011 section 3.1)

#### Version Resolution Algorithm

```
1. Extract Content-Type version (single) and Accept versions (array, can have multiple)
2. If both present and Content-Type version NOT in Accept list -> 409 Conflict
3. Pick version: Content-Type ?? first Accept ?? default (1)
4. If version not in handler map -> 406 Not Acceptable
5. Return the matching VersionedRequest handler
```

#### Endpoint-to-Plugin Communication via VersionContext

The endpoint and response plugins run at different points in Magento's request lifecycle. They communicate via `VersionContext`, a **shared DI singleton** (Magento defaults to shared instances):

```
+---------------------+           +------------------+
|  OrderDeliveryOpts  |           |  VersionContext   |  (shared DI instance)
|  (AbstractEndpoint) |--sets---->|  negotiatedVersion|
|                     |--sets---->|  supportedVersions|
|                     |--sets---->|  isError          |
+---------------------+           +--------+---------+
                                           | reads
                              +------------+-------------+
                              |  VersionContentType plugin|
                              |  afterPrepareResponse()   |
                              |  - Sets Content-Type hdr  |
                              |  - Sets Accept header     |
                              +---------------------------+
```

**Why not signal headers?** ADR-0011 section 1.3 states "Other versioning methods MUST NOT be used." Custom `X-MyParcel-*` headers leak implementation details into the HTTP response and violate this rule even if cleaned up before sending — another plugin or middleware could observe them.

#### Request vs Resource Version

The response `Content-Type` version comes from the **Resource class** (`AbstractVersionedResource::getVersion()`), not echoed from the request. This ensures the response always truthfully declares its own format:

```php
$handler  = $this->resolveVersion();          // picks the right Request handler
$resource = new OrderDeliveryOptionsV1Resource($handler->transform($adapter));
$this->setNegotiatedVersion($resource::getVersion());  // version comes from Resource
```

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
|  1. resolveVersion()        |  Content-Type -> Accept -> default 1
|     - Check 409 conflict    |  Content-Type version not in Accept list
|     - Check 406 unsupported |  version not in handler map
|     - Set supportedVersions |  on VersionContext
|  2. Validate orderId        |  -> 400 if invalid
|  3. Load order              |  -> 404 if not found
|  4. Delegate to V1Request   |  transform(adapter) -> data array
|  5. Wrap in V1Resource      |  data -> Resource object
|  6. setNegotiatedVersion()  |  from Resource::getVersion()
|  7. Return json_encode()    |  Resource::format()
+-------------+---------------+
              v
+-----------------------------+
|  VersionContentType plugin  |  afterPrepareResponse()
|  Reads VersionContext:      |
|  - isActive() = false? skip |  Not a MyParcel request
|  - isError()? Content-Type: |  application/problem+json
|  - else: Content-Type:      |  application/json; version=N
|           Accept:           |  application/json; version=1[, ...]
+-----------------------------+
```

### File Structure

```
src/Api/
  OrderDeliveryOptionsInterface.php          # Service contract

src/Model/Rest/
  AbstractEndpoint.php                       # Version detection, 409/406 checks, VersionContext wiring
  AbstractVersionedRequest.php               # Base for request-side version handlers (transform)
  AbstractVersionedResource.php              # Base for response-side version wrappers (format + getVersion)
  VersionContext.php                         # Shared DI service for endpoint <-> plugin communication
  ProblemDetails.php                         # RFC 9457 value object
  OrderDeliveryOptions.php                   # Main endpoint implementation

src/Model/Rest/Request/
  OrderDeliveryOptionsV1Request.php          # V1 request handler — transforms SDK adapter to V1 array

src/Model/Rest/Resource/
  OrderDeliveryOptionsV1Resource.php         # V1 resource — wraps data, declares version=1

src/Model/Rest/Transformer/
  CarrierTransformer.php                     # lowercase -> SCREAMING_SNAKE_CASE
  PackageTypeTransformer.php                 # SDK name -> API name
  DeliveryTypeTransformer.php                # SDK name -> API name
  ShipmentOptionsTransformer.php             # bool -> {}, insurance -> micro
  DateTransformer.php                        # string -> ISO 8601
  PickupLocationTransformer.php              # SDK -> structured object

src/Plugin/.../Rest/Response/
  VersionContentType.php                     # Sets Content-Type + Accept headers from VersionContext
  ProblemDetailsError.php                    # Intercepts exceptions -> RFC 9457, sets VersionContext error
```

### Plugin DI Wiring

Both plugins and `AbstractEndpoint` receive the **same** `VersionContext` instance via constructor injection. No `di.xml` configuration needed — Magento auto-wires concrete classes as shared instances.

```xml
<!-- di.xml — these plugins are already registered, VersionContext is auto-wired -->
<type name="Magento\Framework\Webapi\Rest\Response">
    <plugin name="myparcel-problem-details-error" sortOrder="5"
            type="...\ProblemDetailsError"/>
    <plugin name="myparcel-version-content-type" sortOrder="10"
            type="...\VersionContentType"/>
</type>
```

**Sort order matters:** `ProblemDetailsError` (sortOrder=5) runs before `VersionContentType` (sortOrder=10). When ProblemDetailsError intercepts an exception, it sets `VersionContext::isError = true`, which tells VersionContentType to use `application/problem+json` instead of versioned JSON.

### Error Handling (RFC 9457)

All errors return Problem Details format with `Content-Type: application/problem+json; charset=utf-8`:

| Status | Title | When |
|--------|-------|------|
| 400 | Invalid Request | `orderId` missing or non-positive |
| 404 | Order Not Found | No order with given ID |
| 406 | Unsupported API Version | Requested version not in handler map |
| 409 | Incompatible Version Headers | Content-Type version not listed in Accept versions (ADR-0011 section 5.2) |
| 500 | Internal Server Error | Unexpected exception (no internal details leaked) |

Format: `{ "type": null, "status": N, "title": "...", "detail": "..." }`

**Error flow:** Errors can originate in two places:
1. **Inside the endpoint** (`errorResponse()`) — sets `VersionContext::isError = true` directly
2. **As thrown exceptions** (`WebapiException`) — caught by `ProblemDetailsError` plugin, which sets `VersionContext::isError = true`

In both cases, `VersionContentType` sees `isError = true` and sets `Content-Type: application/problem+json; charset=utf-8`.

### Adding a New Versioned Endpoint (Checklist)

1. Create interface in `src/Api/` and register in `webapi.xml`
2. Create request handler: `src/Model/Rest/Request/{Name}V{N}Request.php` extends `AbstractVersionedRequest`
3. Create resource: `src/Model/Rest/Resource/{Name}V{N}Resource.php` extends `AbstractVersionedResource`
4. Create endpoint: `src/Model/Rest/{Name}.php` extends `AbstractEndpoint`
   - Constructor: pass `Request`, `Response`, `VersionContext`, plus own deps to parent
   - `getVersionHandlers()`: return `[1 => $this->v1Request]`
   - Business method: call `resolveVersion()`, do work, wrap in Resource, call `setNegotiatedVersion($resource::getVersion())`
5. Register `<preference>` in `di.xml`
6. No plugin changes needed — `VersionContentType` and `ProblemDetailsError` work for all MyParcel endpoints

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
