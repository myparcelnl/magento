# FR-000004: Delivery Options API Documentation

## Parent Requirement

- **Business Requirement:** [BR-000001 - Delivery Options Retrieval Endpoint](../business-requirements/BR-000001-delivery-options-retrieval-endpoint.md)
- **Related User Stories:** [US-000004 - Explore and Build Against the API Contract from an OpenAPI Schema](../user-stories/US-000004-explore-api-contract-from-openapi-schema.md)

## Description

The system must provide an OpenAPI schema that documents the delivery options endpoint. The schema serves as the single source of truth for integration consumers, covering request parameters, success response structure, error responses, and versioning headers.

The documentation must cover:

- **Endpoint:** `GET /V1/myparcel/delivery-options`
- **Request parameters:** `orderId` query parameter (required, integer)
- **Request headers:** `Accept` and `Content-Type` with version parameter
- **Success response (200):** Full response schema including carrier enum values, package type enum values, delivery type enum values, shipment options object structure (empty objects for booleans, insurance micro-currency, label text), date format, and pickup location structure
- **Error responses:** 400, 404, 406, 500 with RFC 9457 Problem Details schema
- **Response headers:** Content-Type with version parameter

## User Impact

**Integration consumers** can auto-generate client code from the OpenAPI schema, validate their requests and responses, and explore the API contract without reading source code. This reduces onboarding time and integration errors.

**Internal development teams** can validate that the implementation matches the documented contract, catching drift between code and documentation.

## Acceptance Criteria

- [ ] An OpenAPI 3.0+ schema file exists that documents the delivery options endpoint
- [ ] The schema includes all request parameters (orderId as required integer query parameter)
- [ ] The schema documents versioning headers (Accept, Content-Type with version parameter)
- [ ] The schema defines the success response structure with all field types and enum values
- [ ] The schema defines all error response structures conforming to RFC 9457
- [ ] The schema validates against the actual endpoint implementation (response matches schema)
- [ ] Carrier, package type, and delivery type enum values are listed in the schema

## Priority

**Classification:** Should Have

**Justification:** Documentation is a success criterion of BR-000001 and is essential for developer experience, but the endpoint can function without it. It should be created alongside or shortly after the endpoint implementation.

## Technical Considerations

### Referenced Technical Requirements

— (No TRs created yet)

### Referenced Architectural Decisions

- **API Design Reference 0014** — defines field naming conventions and response structure that the schema must reflect

### Notes

The OpenAPI schema can be maintained as a YAML or JSON file in the `docs/` directory. Consider using tools like `spectral` for schema linting and validation against the live endpoint.

## Dependencies

### Upstream (this FR depends on)

- FR-000001 (Retrieve Delivery Options) — must be implemented first to document
- FR-000002 (API Version Negotiation) — versioning headers must be finalized
- FR-000003 (Standardized Error Responses) — error format must be finalized

### Downstream (depends on this FR)

— (No downstream dependencies)

## Cross-References

—

## Implementation Notes

Refer to the [implementation design document](../design/delivery-options-endpoint-design.md) for the complete data mapping tables and response structure that the schema must reflect.
