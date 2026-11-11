# TR-000002: Reusable Versioning Infrastructure

## Related Functional Requirements

- [FR-000002 - API Version Negotiation via HTTP Headers](../functional-requirements/FR-000002-api-version-negotiation.md)

## Related ADRs

- [ADR-0011 - API Versioning via Headers](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md) — defines the versioning strategy that this infrastructure must implement as reusable abstractions

## Category

Scalability

## Requirement

Version detection, validation, and response formatting must be encapsulated in abstract base classes that any future MyParcel REST endpoint can extend without reimplementing versioning logic.

## Rationale

FR-000002 requires that the versioning mechanism be reusable across all future MyParcel REST endpoints. Without a formal specification for the abstract class contracts and extensibility guarantees, each new endpoint risks reimplementing version logic inconsistently. This TR defines the class contracts and extensibility criteria as measurable requirements, ensuring the infrastructure scales to support new endpoints and new versions with minimal effort.

## Specifications

### Class Contracts

**`AbstractEndpoint`:**

- Detects version from request headers (per TR-000001 parsing rules)
- Validates version against the subclass-declared list of supported versions
- Returns HTTP 406 error for unsupported versions (per FR-000003 ProblemDetails format)
- Sets `Content-Type: application/json; version={N}` on success responses
- Provides `errorResponse(ProblemDetails): string` helper method

**`AbstractVersionedRequest`:**

- Declares which version it handles via a version identifier
- Exposes `transform(mixed $data): array` method for version-specific response transformation
- Subclass implements transformation logic per version

**`AbstractVersionedResource`:**

- Declares which version it handles via `getVersion(): int`
- Exposes `format(): array` method for version-specific response formatting
- Creates response with `Content-Type: application/json; version={N}` where N comes from `getVersion()`
- The response version is determined by the Resource class, not by the request — ensuring the response always accurately declares its own format

### Scalability Criteria

- **Adding a new endpoint:** Extend `AbstractEndpoint`, register version handlers — no changes to base classes required
- **Adding a new version to an existing endpoint:** Create new `AbstractVersionedRequest` and `AbstractVersionedResource` subclasses — no changes to base classes or existing version handlers required

## Verification Method

Unit tests using mock implementations that exercise the extension points without depending on real endpoint logic.

### Test Scenarios

1. **Mock endpoint dispatches correctly:** A mock endpoint extending `AbstractEndpoint` with a mock V1 handler correctly dispatches a version 1 request, returns the transformed response, and sets the `Content-Type: application/json; version=1` response header
2. **New version handler is additive:** Adding a mock V2 handler to the mock endpoint works correctly without modifying the V1 handler or `AbstractEndpoint` — both V1 and V2 requests dispatch to their respective handlers

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Response` allows setting custom `Content-Type` headers
- The service contract pattern (returning `string`) gives full control over response serialization and headers
- All MyParcel REST endpoints will use the same versioning mechanism (no endpoint-specific version detection)

## Constraints

- Must work within Magento's service contract architecture where REST endpoints are PHP classes implementing interfaces registered in `webapi.xml`
- The abstract classes must not depend on any specific endpoint's domain logic
