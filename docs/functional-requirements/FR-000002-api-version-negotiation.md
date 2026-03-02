# FR-000002: API Version Negotiation via HTTP Headers

## Parent Requirement

- **Business Requirement:** [BR-000001 - Delivery Options Retrieval Endpoint](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)
- **Related User Stories:** [US-000002 - Pin My Integration to a Specific API Version](../user-stories/US-000002-pin-integration-to-api-version.md)

## Description

The system must support API version negotiation via HTTP headers for all MyParcel REST endpoints, starting with the delivery options endpoint. The versioning mechanism must be implemented as reusable abstractions that future endpoints can adopt without reimplementation.

**Version detection precedence:**

1. `Content-Type` header (highest priority) — e.g., `Content-Type: application/json; version=1`
2. `Accept` header (fallback) — e.g., `Accept: application/json; version=1`
3. Default to version 1 if no version specified

**Version extraction:** Regex `/version=v?(\d+)/i` — case-insensitive, supports optional `v` prefix, extracts major version number only.

**Unsupported version handling:** Return HTTP 406 (Not Acceptable) with a detail message listing supported versions.

**Response headers:** The response must include the version used in both `Content-Type` and `Accept` headers (e.g., `Content-Type: application/json; version=1`). The version in the response `Content-Type` is determined by the `AbstractVersionedResource` class that formats the response, not echoed from the request. This ensures the response always truthfully declares its own format version.

**Incompatible header versions:** When both `Content-Type` and `Accept` headers contain version parameters but the `Content-Type` version is not listed in the `Accept` header's version list, the server must return HTTP 409 (Conflict) with a detail message (per ADR-0011 section 5.2).

**Reusable abstractions:** The version detection, validation, and response header logic must be encapsulated in abstract base classes (`AbstractEndpoint`, `AbstractVersionedRequest`, `AbstractVersionedResource`) that can be extended by any future MyParcel REST endpoint.

## User Impact

**Integration consumers** get predictable API evolution. When a V2 format is introduced, existing consumers continue receiving V1 responses until they opt in by changing their request headers. No breaking changes.

**Internal development teams** get reusable infrastructure. Adding versioning to a new endpoint requires extending the abstract classes, not reimplementing version detection.

## Acceptance Criteria

- [ ] Version is detected from `Content-Type` header when present (e.g., `Content-Type: application/json; version=1`)
- [ ] Version falls back to `Accept` header when `Content-Type` has no version parameter
- [ ] Version defaults to 1 when neither header contains version information
- [ ] Version extraction regex `/version=v?(\d+)/i` correctly handles: `version=1`, `version=v1`, `version=V1`, `VERSION=1`
- [ ] Requesting an unsupported version returns HTTP 406 with detail: `"API version {v} is not supported. Supported versions: {list}"`
- [ ] Success responses include `Content-Type: application/json; version=1` header
- [ ] Requesting with incompatible versions in `Content-Type` and `Accept` headers returns HTTP 409 with detail message (per ADR-0011 section 5.2)
- [ ] `AbstractEndpoint` base class is reusable — a new endpoint can extend it and add its own version handlers
- [ ] `AbstractVersionedRequest` base class is reusable — a new version handler can extend it with its own `transform()` method
- [ ] `AbstractVersionedResource` base class is reusable — a new response version can extend it with its own `format()` method
- [ ] Only version 1 is supported initially
- [ ] V1 response body structure matches the `DeliveryOptions` schema from the [PDK OpenAPI spec (`openapi-delivery-options-v1.yaml`)](https://github.com/myparcelnl/pdk/blob/feat/delivery-options-endpoint/src/App/Endpoint/openapi-delivery-options-v1.yaml)
- [ ] Response top-level keys are: `carrier`, `packageType`, `deliveryType`, `shipmentOptions`, `date`, `pickupLocation`
- [ ] Carrier, packageType, and deliveryType values use SCREAMING_SNAKE_CASE enum values from the Order Service API
- [ ] Boolean shipment options produce `{}` (empty object) when enabled; the key is omitted when disabled (ADR-0013)
- [ ] Insurance produces `{ "amount": <integer-micro>, "currency": "EUR" }` following ADR-0014 micro-currency format
- [ ] Label description produces `{ "text": "<string>" }` as `customLabelText`
- [ ] Unit tests validate each transformer's output against the PDK spec schema

## V1 Response Contract

Version 1 responses **MUST** conform to the [PDK's OpenAPI spec (`openapi-delivery-options-v1.yaml`)](https://github.com/myparcelnl/pdk/blob/feat/delivery-options-endpoint/src/App/Endpoint/openapi-delivery-options-v1.yaml). This Magento module is an implementation of that same contract — the PDK spec is the **single source of truth** for the response shape.

The Magento-local OpenAPI spec (`docs/openapi/delivery-options.yaml`) mirrors the PDK spec for documentation and testing purposes. When the PDK spec evolves, this module's spec and transformers must be updated to match.

Key contract rules:

- **Enum values** — carrier, packageType, and deliveryType use SCREAMING_SNAKE_CASE constants from the Order Service (e.g., `POSTNL`, `PACKAGE`, `STANDARD_DELIVERY`)
- **Boolean shipment options** — represented as empty objects `{}` when enabled, omitted when disabled (ADR-0013 empty-object standard)
- **Insurance** — `{ "amount": <integer-micro>, "currency": "EUR" }` where amount is in micro-currency units (1 EUR = 1,000,000 micros) per ADR-0014
- **Label description** — `{ "text": "<string>" }` as the `customLabelText` key
- **Null fields** — when no delivery options are stored, all top-level keys are `null`

## Priority

**Classification:** Must Have

**Justification:** API versioning is required by ADR-0011 and is a success criterion of BR-000001. The reusable abstractions establish the foundation for consistent API design across all future MyParcel Magento endpoints.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000001 - HTTP Version Header Parsing](../technical-requirements/TR-000001-http-version-header-parsing.md) — regex pattern, header precedence, default version, and error handling specifications
- [TR-000002 - Reusable Versioning Infrastructure](../technical-requirements/TR-000002-reusable-versioning-infrastructure.md) — abstract class contracts and extensibility criteria for `AbstractEndpoint`, `AbstractVersionedRequest`, and `AbstractVersionedResource`
- [TR-000003 - V1 Response Schema Conformance](../technical-requirements/TR-000003-v1-response-schema-conformance.md) — PDK spec conformance for field formats, enum values, and data transformations

### Referenced Architectural Decisions

- **ADR-0011** — API Versioning via Headers: defines the versioning strategy, header precedence, and regex pattern that this FR implements
- **ADR-0013** — Various API Design Rules: empty-object standard for boolean shipment options (`{}` = enabled, key omitted = disabled), Order Service enum names
- **ADR-0014** — API Design Standards: micro-currency format for monetary values, camelCase field naming, SCREAMING_SNAKE_CASE enum values
- [**PDK OpenAPI spec** (`openapi-delivery-options-v1.yaml`)](https://github.com/myparcelnl/pdk/blob/feat/delivery-options-endpoint/src/App/Endpoint/openapi-delivery-options-v1.yaml) — canonical V1 response contract; this module's response shape must match

### Notes

Magento's REST framework has its own content-type negotiation. The version detection operates within the service contract implementation, reading raw headers via `Magento\Framework\Webapi\Rest\Request`. The service contract returns `string` (raw JSON) to bypass Magento's serializer and maintain control over response headers.

## Dependencies

### Upstream (this FR depends on)

- Magento `\Magento\Framework\Webapi\Rest\Request` for header access
- Magento `\Magento\Framework\Webapi\Rest\Response` for response header control
- [PDK delivery options OpenAPI spec v1 (`openapi-delivery-options-v1.yaml`)](https://github.com/myparcelnl/pdk/blob/feat/delivery-options-endpoint/src/App/Endpoint/openapi-delivery-options-v1.yaml) — canonical response contract that defines the V1 response shape

### Downstream (depends on this FR)

- FR-000001 (Retrieve Delivery Options) — uses the versioning infrastructure
- Any future MyParcel REST endpoint that needs versioning

## Cross-References

—

## Implementation Notes

Refer to the [implementation design document](../design/delivery-options-endpoint-design.md) for the `AbstractEndpoint` class design and version handler pattern.
