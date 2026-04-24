# TR-000001: HTTP Version Header Parsing

## Related Functional Requirements

- [FR-000002 - API Version Negotiation via HTTP Headers](../functional-requirements/FR-000002-api-version-negotiation.md)

## Related ADRs

- [ADR-0011 - API Versioning via Headers](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md) — defines the versioning strategy, header precedence, regex pattern, and default version behavior that this TR specifies measurable criteria for

## Category

Compatibility

## Requirement

The system must extract an integer major version number from HTTP request headers using a defined regex pattern, with defined precedence and default behavior.

## Rationale

API version negotiation (FR-000002) requires a deterministic, well-specified mechanism for extracting version numbers from HTTP headers. This TR captures the exact parsing rules — regex pattern, header precedence, default behavior, and error handling — as standalone, testable specifications. By isolating parsing rules from the broader versioning infrastructure, each concern can be verified independently and reused by any endpoint that needs version detection.

## Specifications

### Compatibility Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Regex pattern | `/version=v?(\d+)/i` — case-insensitive, optional `v` prefix, extracts integer | Unit test: all documented input patterns match and extract correctly |
| Header precedence | `Content-Type` > `Accept` > default | Unit test: precedence matrix covering all combinations |
| Default version | `1` when no version parameter is present in any header | Unit test: empty/missing headers return version `1` |
| Unsupported version | HTTP 406 (Not Acceptable) with Problem Details response body listing supported versions | Unit test: unsupported version number returns 406 |

## Verification Method

Unit tests covering the full input matrix. Each test scenario exercises a single parsing rule in isolation.

### Test Scenarios

1. **Content-Type with version:** `Content-Type: application/json; version=1` extracts version `1`
2. **Accept with v-prefix:** `Accept: application/json; version=v2` extracts version `2`
3. **Content-Type takes precedence:** Both `Content-Type` and `Accept` present with different version values — `Content-Type` version wins
4. **Case-insensitive matching:** `VERSION=1` (uppercase) extracts version `1`
5. **Default when absent:** No version parameter in any header defaults to version `1`
6. **Unsupported version rejection:** `version=99` (not in supported list) returns HTTP 406 with Problem Details body
7. **Incompatible header versions:** `Content-Type: application/json; version=1` with `Accept: application/json; version=2; version=3` (Content-Type version not in Accept list) returns HTTP 409 Conflict

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Request` provides access to raw `Content-Type` and `Accept` header values
- Only major version numbers are used (no minor/patch versioning)
- The version parameter appears as a media type parameter (e.g., `application/json; version=1`), not as a standalone header

## Constraints

- Must operate within Magento's REST framework, which has its own content-type negotiation — version detection reads headers but does not interfere with Magento's built-in negotiation
