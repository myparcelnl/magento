# ADR-0014: API Design Standards

**Date:** 02-10-2025    
**Author:** Erik Paul  


## References

For full background, details, and diagrams, see:

- [`02-reference/0014-api-design-reference`](/02-reference/0014-api-design-reference.md)


## Y-Statement
In the context of **multiple teams building APIs and microservices** using different naming conventions, unit formats, and contract styles, facing **inconsistent API contracts, subjective debates in PR reviews, and higher client integration costs**, we decided to **adopt a single API Design Standard based on industry guidelines (Google API Design Guide, Microsoft REST Guidelines, CloudEvents, AsyncAPI, Protobuf/Buf, SI/ISO standards)**, to achieve **consistent and predictable API contracts, enforceable via CI linting, easy SDK generation, and zero subjective debate**, while allowing **incremental migration by linting only changed files and deprecating legacy fields gracefully**.


## Context
Our current API landscape (REST, Intent-based, events etc.) has grown organically with inconsistent naming, field casing, and unit usage. This leads to recurring discussions in code reviews, duplicated client logic, and unclear expectations between teams. There is also no single reference document describing the standards we follow.

### Relation to ADR-0013

[ADR-0013](0013-various-api-design-rules.md) was written during the Capabilities API design and records specific decisions about shipment option structures, enum naming sourced from the Order Service, and general formatting rules for fields, enums, monetary objects, and physical quantities.

ADR-0014 **supersedes** ADR-0013 by establishing a comprehensive, organisation-wide API design standard that absorbs and extends the general rules introduced in ADR-0013.

**Absorbed into ADR-0014** (these topics are now governed by this ADR):
- camelCase field naming (ADR-0013 decision 3 → reference section 3.1)
- SCREAMING_SNAKE_CASE enum values (ADR-0013 decision 2 → reference section 3.3)
- Physical quantity objects with `value` + `unit` using SI units (ADR-0013 decision 8 → reference section 3.8)
- ISO 4217 currency codes (ADR-0013 decision 7 → reference section 3.10)

**Superseded: monetary serialization**
ADR-0013 decision 7 specifies integer minor units (cents), e.g. `{ "amount": 69, "currency": "EUR" }`. This ADR adopts integer micros (millionths of the major currency unit) instead, providing uniform processing across all currencies and sub-cent precision when needed. See reference section 3.9 and the [currency serialization reference](/02-reference/0014-currency-serialization-reference.md) for the full rationale. This supersedes ADR-0013 decision 7.

**Remaining in ADR-0013** (domain-specific decisions not covered here):
- Adoption of enum names from the Order Service for shipment options
- The `tracked` → `noTracking` inversion
- Requirement to include both `min` and `max` for insurance and physical properties
- Use of `0` instead of absent/null for min values
- The specific request/response structure for shipment options (e.g. `requiresAgeVerification: {}`)


## Decision
1. **Adopt a unified API Design Standard** covering:
   * REST resource design & URI patterns
   * Request & response structure (single resource, batch, idempotency)
   * Filtering, search, sorting & pagination
   * HTTP status codes & error handling (RFC 9457 Problem Details)
   * Versioning (content-type header version parameter; see ADR-0011)
   * Localization (Accept-Language header)
   * Naming & semantics (fields, enums, booleans, headers, abbreviations)
   * Date, time & duration formats (ISO 8601 / RFC 3339)
   * Units (SI standards) and monetary values (integer micros)
   * Codes (ISO 4217 currency, ISO 639-1 language, ISO 3166-1 country)
   * Intent-based endpoints
   * File uploads
   * API documentation (OpenAPI, AsyncAPI, developer portals)
2. **Enforce via CI** (Spectral, ESLint, PHPCS, Buf, GitHub Actions).
3. **Reference Document** will be created containing all details per topic, serving as the *single source of truth* for teams.
4. **Incremental adoption**: only changed files are linted; legacy fields are deprecated with aliases before removal.


## Consequences
* Developers have a single authoritative reference (no debates in PRs).  
* Client SDKs become easier to generate and maintain.  
* Incremental migration avoids a big-bang refactor.  
* Some legacy APIs will need parallel deprecation/aliasing to converge on the new standard.  


## References
* Google API Design Guide: https://cloud.google.com/apis/design
* Microsoft REST API Guidelines: https://github.com/microsoft/api-guidelines
* Zalando RESTful API Guidelines: https://opensource.zalando.com/restful-api-guidelines  
* CloudEvents 1.0: https://cloudevents.io/  
* AsyncAPI 3.0: https://www.asyncapi.com/docs  
* OpenAPI 3.1: https://spec.openapis.org/oas/v3.1.0  
* JSON Schema 2020-12: https://json-schema.org/  
* RFC 7807: https://www.rfc-editor.org/rfc/rfc7807  
* RFC 8288: https://www.rfc-editor.org/rfc/rfc8288  
* RFC 3339: https://www.rfc-editor.org/rfc/rfc3339  
* Buf (Protobuf tooling): https://buf.build/  
* BIPM SI Brochure: https://www.bipm.org/documents/20126/41483022/SI-Brochure-9-EN.pdf  
* ISO Standards (4217 currency, 639-1 language, 3166-1 country): https://www.iso.org/standards.html  

