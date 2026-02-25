# BR-000001: Delivery Options Retrieval Endpoint

## Business Context

External sales channels and orderv2 integrations need to retrieve delivery options (shipping preferences) from Magento orders to fulfill shipments correctly. Currently, delivery options are exposed as a side effect of a plugin on the generic Magento OrderRepository REST API (`OrderInformationUpdate`). This mechanism is not purpose-built for the use case, lacks independent versioning, has no dedicated documentation, and provides no typed contract that integrations can rely on.

As MyParcel expands its integration ecosystem — connecting more sales channels and migrating to orderv2 — the absence of a stable, dedicated API for delivery options creates fragility. Integration consumers must reverse-engineer the existing plugin behavior, and any change to the generic order API can silently break delivery options retrieval.

## Objective

Enable external integrations to reliably retrieve delivery options (shipping preferences) for any Magento order through a dedicated, versioned, typed REST endpoint — providing a stable contract that integration consumers can depend on independently of the generic Magento order API.

## Business Justification

- **Integration reliability:** A dedicated endpoint eliminates the coupling between delivery options retrieval and the generic Magento order API, reducing the risk of silent breakage when Magento or the module is updated.
- **Ecosystem growth:** Sales channel and orderv2 integrations require a stable, documented API to onboard efficiently. A purpose-built endpoint reduces integration development time and support burden.
- **API governance:** Introducing versioned request/response abstractions establishes a foundation for consistent API design across future MyParcel Magento endpoints, reducing long-term maintenance cost.
- **Developer experience:** A typed, documented endpoint with an OpenAPI schema enables integration consumers to auto-generate client code and validate responses, reducing errors and support requests.

## Scope

### In Scope

- Dedicated REST endpoint to retrieve delivery options by Magento order ID
- API versioning via `Accept`/`Content-Type` headers (per ADR-0011)
- Reusable `VersionedRequest`/`VersionedResponse` abstract classes for future endpoint consistency
- Typed response with a separate formatter/resource class (similar to Laravel API Resources)
- DeliveryOptions response model covering: carrier, packageType, deliveryType, shipmentOptions, date/time, pickupLocation
- JSON-only response format
- OpenAPI schema documentation
- Adherence to API Design Reference 0014 (camelCase fields, error handling, response structure)

### Out of Scope

- Alternative response formats (XML, etc.)
- Write or update operations on delivery options
- Authentication or authorization changes beyond Magento's existing REST API mechanisms
- Migration or deprecation of the existing `OrderInformationUpdate` plugin
- Modification of how delivery options are stored in the database

## Success Criteria

- [ ] External integrations can retrieve complete delivery options for an order via a single `GET` request using the Magento order ID
- [ ] Response includes versioning headers conforming to ADR-0011
- [ ] `VersionedRequest`/`VersionedResponse` abstractions are reusable for future MyParcel Magento endpoints
- [ ] An OpenAPI schema is available and validates against the actual endpoint implementation
- [ ] Response format passes API Design Reference 0014 compliance checks (camelCase fields, standard error handling, standard response structure)
- [ ] Integration consumers can adopt the endpoint without changes to their authentication setup

## Stakeholders

| Role | Name | Responsibility |
|---|---|---|
| Business Sponsor | MyParcel | Final approval, strategic direction |
| Product Owner | MyParcel Engineering | Requirements refinement, prioritization |
| Technical Lead | — | Feasibility assessment, implementation oversight |
| End Users | Sales channel / orderv2 integration consumers | Validation and feedback |

## Constraints

- **Technical:** Must work within Magento 2's REST framework and its content-type negotiation mechanisms. Custom versioning headers (ADR-0011) must coexist with Magento's built-in request handling.
- **Technical:** Must be compatible with PHP 7.4+ and PHP 8.0+.
- **Technical:** Must use the existing MyParcel PHP SDK (v10.4+) for any data structures shared with the SDK.

## Dependencies

- **ADR-0011:** API Versioning via Headers — defines the versioning strategy using `Accept`/`Content-Type` headers that this endpoint must implement.
- **API Design Reference 0014:** API Design Reference — defines field naming conventions (camelCase), error response format, and standard response structure that this endpoint must conform to.
- **Existing data model:** Delivery options are stored as JSON in the `sales_order.myparcel_delivery_options` column and parsed via `DeliveryOptionsFromOrderAdapter`. The endpoint depends on this existing storage mechanism.

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Magento's REST framework content-type negotiation conflicts with custom versioning headers (ADR-0011) | Medium | High | Prototype header-based versioning early; fall back to URL-based versioning if framework limitations are insurmountable |
| Existing integrations using the `OrderInformationUpdate` plugin expect the current behavior to persist | Medium | Medium | Keep the existing plugin intact; document the new endpoint as the recommended path; provide migration guidance in a future iteration |
| DeliveryOptions data in older orders may be incomplete or in legacy format | Low | Medium | Handle missing/legacy data gracefully in the response formatter; document which fields are optional |

## Approval

| Role | Name | Date | Status |
|---|---|---|---|
| Business Sponsor | | | Pending |
| Product Owner | | | Pending |
