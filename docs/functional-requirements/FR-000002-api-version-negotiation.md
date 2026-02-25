# FR-000002: API Version Negotiation via HTTP Headers

## Parent Requirement

- **Business Requirement:** [BR-000001 - Delivery Options Retrieval Endpoint](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)
- **Related User Stories:** —

## Description

The system must support API version negotiation via HTTP headers for all MyParcel REST endpoints, starting with the delivery options endpoint. The versioning mechanism must be implemented as reusable abstractions that future endpoints can adopt without reimplementation.

**Version detection precedence:**

1. `Content-Type` header (highest priority) — e.g., `Content-Type: application/json; version=1`
2. `Accept` header (fallback) — e.g., `Accept: application/json; version=1`
3. Default to version 1 if no version specified

**Version extraction:** Regex `/version=v?(\d+)/i` — case-insensitive, supports optional `v` prefix, extracts major version number only.

**Unsupported version handling:** Return HTTP 406 (Not Acceptable) with a detail message listing supported versions.

**Response headers:** The response must include the version used in both `Content-Type` and `Accept` headers (e.g., `Content-Type: application/json; version=1`).

**Reusable abstractions:** The version detection, validation, and response header logic must be encapsulated in abstract base classes (`AbstractEndpoint`, `AbstractVersionedRequest`) that can be extended by any future MyParcel REST endpoint.

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
- [ ] `AbstractEndpoint` base class is reusable — a new endpoint can extend it and add its own version handlers
- [ ] `AbstractVersionedRequest` base class is reusable — a new version handler can extend it with its own `transform()` method
- [ ] Only version 1 is supported initially

## Priority

**Classification:** Must Have

**Justification:** API versioning is required by ADR-0011 and is a success criterion of BR-000001. The reusable abstractions establish the foundation for consistent API design across all future MyParcel Magento endpoints.

## Technical Considerations

### Referenced Technical Requirements

— (No TRs created yet)

### Referenced Architectural Decisions

- **ADR-0011** — API Versioning via Headers: defines the versioning strategy, header precedence, and regex pattern that this FR implements

### Notes

Magento's REST framework has its own content-type negotiation. The version detection operates within the service contract implementation, reading raw headers via `Magento\Framework\Webapi\Rest\Request`. The service contract returns `string` (raw JSON) to bypass Magento's serializer and maintain control over response headers.

## Dependencies

### Upstream (this FR depends on)

- Magento `\Magento\Framework\Webapi\Rest\Request` for header access
- Magento `\Magento\Framework\Webapi\Rest\Response` for response header control

### Downstream (depends on this FR)

- FR-000001 (Retrieve Delivery Options) — uses the versioning infrastructure
- Any future MyParcel REST endpoint that needs versioning

## Cross-References

—

## Implementation Notes

Refer to the [implementation design document](../design/delivery-options-endpoint-design.md) for the `AbstractEndpoint` class design and version handler pattern.
