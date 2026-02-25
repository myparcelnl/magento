# FR-000001: Retrieve Delivery Options for an Order

## Parent Requirement

- **Business Requirement:** [BR-000001 - Delivery Options Retrieval Endpoint](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)
- **Related User Stories:** —

## Description

The system must provide a dedicated REST endpoint that returns the complete delivery options for a given Magento order, formatted in the Order Service API format.

Given a Magento order ID, the endpoint returns a JSON response containing:

- **carrier** — carrier identifier in SCREAMING_SNAKE_CASE enum format (e.g., `POSTNL`, `DHL_FOR_YOU`, `UPS_STANDARD`)
- **packageType** — package type in SCREAMING_SNAKE_CASE enum format (e.g., `PACKAGE`, `MAILBOX`, `UNFRANKED`, `DIGITAL_STAMP`, `SMALL_PACKAGE`)
- **deliveryType** — delivery type in SCREAMING_SNAKE_CASE enum format with `_DELIVERY` suffix (e.g., `STANDARD_DELIVERY`, `MORNING_DELIVERY`, `PICKUP_DELIVERY`)
- **shipmentOptions** — object containing only enabled options:
  - Boolean options represented as empty objects `{}` (e.g., `"requiresSignature": {}`)
  - Insurance as micro-currency object (`{ "amount": 50000000, "currency": "EUR" }`)
  - Label description as text object (`{ "text": "..." }`)
  - Disabled/false options omitted entirely
- **date** — delivery date in ISO 8601 format with timezone (`2025-03-15T00:00:00+01:00`), or `null` if not set
- **pickupLocation** — structured pickup location object with address when delivery type is pickup, or `null` otherwise

Orders that exist but have no MyParcel delivery options must return a valid response with all fields set to `null` (not an error).

The endpoint URL is `GET /V1/myparcel/delivery-options` with `orderId` as a query parameter.

## User Impact

**Sales channel / orderv2 integration consumers** can retrieve delivery options for any Magento order via a single, predictable GET request. This replaces the need to parse the generic Order API's extension attributes and reverse-engineer the data format. Integration developers get a stable, typed contract they can code against with confidence.

## Acceptance Criteria

- [ ] `GET /V1/myparcel/delivery-options?orderId={id}` returns HTTP 200 with the complete delivery options in Order Service API format
- [ ] Carrier names are mapped from internal lowercase format to SCREAMING_SNAKE_CASE enum values (including non-obvious mappings like `dhlforyou` to `DHL_FOR_YOU`, `ups` to `UPS_STANDARD`, `letter` to `UNFRANKED`)
- [ ] Enabled boolean shipment options are represented as empty objects `{}`; disabled options are omitted from the response
- [ ] Insurance amount is expressed in micro-currency units (value multiplied by 1,000,000) with `currency: "EUR"`
- [ ] Label description is wrapped in a text object (`{ "text": "..." }`)
- [ ] Date is formatted as ISO 8601 with `Europe/Amsterdam` timezone
- [ ] Pickup location includes structured address object when delivery type is pickup
- [ ] Orders without delivery options return HTTP 200 with all fields as `null`
- [ ] Response fields use camelCase naming per API Design Reference 0014
- [ ] The endpoint is accessible anonymously (matches existing MyParcel endpoint access pattern)

## Priority

**Classification:** Must Have

**Justification:** This is the core capability that BR-000001 exists to deliver. Without this FR, the business objective of enabling external integrations to retrieve delivery options cannot be met.

## Technical Considerations

### Referenced Technical Requirements

— (No TRs created yet)

### Referenced Architectural Decisions

- **ADR-0013** — Empty object standard: boolean shipment options use `{}` instead of `true`
- **API Design Reference 0014** — camelCase field naming, response structure conventions

### Notes

The response formatting is implemented as a separate resource/formatter class (similar to Laravel API Resources) composed of individual transformers for each data type. This enables reuse and independent testing. See [design document](../design/delivery-options-endpoint-design.md) for full mapping tables.

The endpoint reads from the existing `sales_order.myparcel_delivery_options` JSON column via the SDK's `DeliveryOptionsAdapterFactory`. No database changes required.

## Dependencies

### Upstream (this FR depends on)

- Existing `sales_order.myparcel_delivery_options` data storage (already in place)
- MyParcel PHP SDK v10.4+ adapter classes (already installed)

### Downstream (depends on this FR)

- FR-000002 (API Version Negotiation) — the endpoint uses versioning infrastructure
- FR-000003 (Standardized Error Responses) — the endpoint uses error response infrastructure
- FR-000004 (API Documentation) — documents this endpoint's contract

## Cross-References

—

## Implementation Notes

Refer to the [implementation design document](../design/delivery-options-endpoint-design.md) for the complete data mapping tables, architecture diagram, and verified SDK dependencies.
