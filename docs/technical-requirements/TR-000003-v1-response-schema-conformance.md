# TR-000003: V1 Response Schema Conformance

## Related Functional Requirements

- [FR-000002 - API Version Negotiation via HTTP Headers](../functional-requirements/FR-000002-api-version-negotiation.md)
- [FR-000001 - Retrieve Delivery Options for an Order](../functional-requirements/FR-000001-retrieve-delivery-options.md)

## Related ADRs

- [ADR-0013 - Various API Design Rules](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0013-various-api-design-rules.md) — empty-object boolean standard (`{}` = enabled, key omitted = disabled) and Order Service enum naming
- [ADR-0014 - API Design Standards](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0014-api-design-standards.md) — micro-currency format for monetary values and camelCase field naming

## Category

Compatibility

## Requirement

V1 responses must conform to the `DeliveryOptions` schema defined in the [canonical OpenAPI spec](https://api.myparcel.nl/openapi.min.json). This spec is the single source of truth for the response shape.

## Rationale

Both FR-000001 and FR-000002 require that the delivery options response matches the PDK's canonical schema. This TR captures the specific field formats, enum conventions, and data transformations as standalone, measurable criteria. By defining schema conformance as a reusable TR, any endpoint that returns delivery options data (current or future) references the same specification — ensuring consistency and preventing drift from the PDK contract.

## Specifications

### Compatibility Criteria

| Field | Format | Source |
|-------|--------|--------|
| Top-level keys | `carrier`, `packageType`, `deliveryType`, `shipmentOptions`, `date`, `pickupLocation` | PDK spec |
| Enum values | SCREAMING_SNAKE_CASE from Order Service API | ADR-0013 |
| Boolean shipment options | `{}` (empty object) when enabled, key omitted when disabled | ADR-0013 |
| Insurance | `{ "amount": <integer-micro>, "currency": "EUR" }` where 1 EUR = 1,000,000 micros | ADR-0014 |
| Label description | `{ "text": "<string>" }` as `customLabelText` | PDK spec |
| Date | ISO 8601 with `Europe/Amsterdam` timezone offset (e.g., `2025-03-15T00:00:00+01:00`) | ADR-0014 |
| Null fields | All top-level keys are `null` when no delivery options are stored for the order | PDK spec |

## Verification Method

Unit tests for each transformer validate output format. A full integration test validates the complete response against the OpenAPI schema.

### Test Scenarios

1. **Carrier enum mapping:** `CarrierTransformer` output is a valid carrier enum value from the PDK spec (e.g., `postnl` maps to `POSTNL`, `dhlforyou` maps to `DHL_FOR_YOU`)
2. **Package type enum mapping:** `PackageTypeTransformer` maps all SDK package type names to spec enum values, including non-obvious mappings (`letter` to `UNFRANKED`, `package_small` to `SMALL_PACKAGE`)
3. **Delivery type enum mapping:** `DeliveryTypeTransformer` maps all SDK delivery type names to spec enum values with `_DELIVERY` suffix (e.g., `standard` to `STANDARD_DELIVERY`, `morning` to `MORNING_DELIVERY`)
4. **Boolean shipment options:** `ShipmentOptionsTransformer` produces `{}` for enabled boolean options and omits disabled ones from the output
5. **Insurance micro-currency:** `ShipmentOptionsTransformer` produces `{ "amount": N, "currency": "EUR" }` for insurance with correct micro-currency conversion (e.g., 50 EUR becomes `50000000`)
6. **Date formatting:** `DateTransformer` produces ISO 8601 format with `Europe/Amsterdam` timezone offset
7. **Full schema validation:** Complete `OrderDeliveryOptionsV1Request` output validates against the `DeliveryOptions` OpenAPI schema from `https://api.myparcel.nl/openapi.min.json`

## Assumptions

- The [PDK OpenAPI spec (`openapi-delivery-options-v1.yaml`)](https://github.com/myparcelnl/pdk/blob/feat/delivery-options-endpoint/src/App/Endpoint/openapi-delivery-options-v1.yaml) is the single source of truth for the V1 response shape
- The canonical spec at `https://api.myparcel.nl/openapi.min.json` is the single source of truth
- All SDK delivery option field names are known and have defined mappings to the spec enum values

## Constraints

- Enum values must match the Order Service API exactly — no custom or module-specific enum values
- The micro-currency multiplier (1,000,000) is fixed and must not be configurable
- Timezone for date formatting is always `Europe/Amsterdam`, not the Magento store's configured timezone
