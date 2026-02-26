# FR-000003: Standardized Error Responses

## Parent Requirement

- **Business Requirement:** [BR-000001 - Delivery Options Retrieval Endpoint](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)
- **Related User Stories:** [US-000003 - Diagnose Integration Failures from Error Responses](../user-stories/US-000003-diagnose-integration-failures-from-errors.md)

## Description

The system must return all API errors in a standardized format conforming to RFC 9457 (Problem Details for HTTP APIs). This provides integration consumers with a consistent, machine-readable error contract across all MyParcel REST endpoints.

**Error response format:**

```json
{
  "type": null,
  "status": 400,
  "title": "Invalid Request",
  "detail": "Request validation failed: orderId"
}
```

**Fields:**
- `type` — URI identifying the problem type (null for standard HTTP errors)
- `status` — HTTP status code (integer)
- `title` — human-readable summary of the problem type
- `detail` — human-readable explanation specific to this occurrence

**Content-Type:** Error responses must use `Content-Type: application/problem+json`.

**Error cases for the delivery options endpoint:**

| Status | Title | Detail |
|--------|-------|--------|
| 400 | Invalid Request | `Request validation failed: orderId` |
| 404 | Order Not Found | `Order with id {id} was not found` |
| 406 | Unsupported API Version | `API version {v} is not supported. Supported versions: {list}` |
| 500 | Internal Server Error | `An unexpected error occurred` |

**Security:** Internal error details (stack traces, exception messages) must never be exposed in the `detail` field of 500 responses.

**Reusability:** The `ProblemDetails` value object and error response helpers must be reusable by any future MyParcel REST endpoint.

## User Impact

**Integration consumers** get consistent, predictable error responses they can handle programmatically. The standardized format enables them to build generic error handling rather than endpoint-specific parsing. The `detail` field provides actionable information for debugging.

## Acceptance Criteria

- [ ] All error responses conform to RFC 9457 structure: `{ type, status, title, detail }`
- [ ] Error responses use `Content-Type: application/problem+json`
- [ ] HTTP 400 is returned when `orderId` is missing or invalid (not a positive integer)
- [ ] HTTP 404 is returned when the order does not exist, with the order ID in the detail message
- [ ] HTTP 406 is returned when an unsupported API version is requested, with supported versions listed
- [ ] HTTP 500 is returned for unexpected errors, with a generic detail message (no internal details leaked)
- [ ] The `ProblemDetails` class is reusable — it can be instantiated for any status/title/detail combination
- [ ] Error response helper methods are available in `AbstractEndpoint` for use by any endpoint

## Priority

**Classification:** Must Have

**Justification:** Standardized error handling is required by API Design Reference 0014 and is a success criterion of BR-000001. Integration consumers need consistent error responses to build reliable integrations.

## Technical Considerations

### Referenced Technical Requirements

— (No TRs created yet)

### Referenced Architectural Decisions

- **API Design Reference 0014** — defines error response format and structure requirements
- **RFC 9457** — Problem Details for HTTP APIs (IETF standard)

### Notes

The `ProblemDetails` class implements `JsonSerializable` for clean serialization. The `AbstractEndpoint` base class provides `errorResponse(ProblemDetails): string` which sets the HTTP status code via `RestResponse::setHttpResponseCode()` and the content-type header.

## Dependencies

### Upstream (this FR depends on)

- Magento `\Magento\Framework\Webapi\Rest\Response` for HTTP status code control

### Downstream (depends on this FR)

- FR-000001 (Retrieve Delivery Options) — uses the error response infrastructure
- Any future MyParcel REST endpoint that needs error handling

## Cross-References

—

## Implementation Notes

Refer to the [implementation design document](../design/delivery-options-endpoint-design.md) for the `ProblemDetails` class design and error response helper pattern.
