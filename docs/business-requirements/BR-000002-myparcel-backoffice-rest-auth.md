# BR-000002: MyParcel Backoffice Authenticates Against Customer Magento REST API

## Business Context

MyParcel operates a SaaS backoffice that needs read access to its customers' Magento store data — specifically order data and the delivery options associated with those orders — to power merchant-facing tooling (label generation, shipment tracking, fulfilment workflows).

Today, granting that access requires the customer's Magento admin to either run CLI commands (`bin/magento config:set …`) or use OAuth 1.0a, which MyParcel does not support. Magento 2.4.4+ disabled the simpler bearer-token path by default (`oauth/consumer/enable_integration_as_bearer = 0`). We are not asking all our customers to run specific cli commands, this would require a lot of support.

## Objective

A Magento shop admin can connect their store to MyParcel in **under 30 seconds**, without:

- running any `bin/magento` command,
- interacting with the *Integrations* admin screen.

The connection mechanism must reuse Magento's native ACL machinery so per-resource access is governed by the module's declared grants and survives future ACL resource additions.

## Business Justification

- **Lower onboarding friction for SMB merchants:** Many MyParcel customers are SMBs whose Magento admin is non-technical. Anything that requires CLI access or terminology like "OAuth consumer" is a stop point.
- **Prerequisite for downstream features:** Several planned MyParcel features (order- and product integration, delivery-options sync) consume Magento data via REST. Without a low-friction auth mechanism, those features can't ship.
- **Risk mitigation:** A self-service rotation flow lets customers respond to suspected token compromise without waiting on MyParcel support.

## Scope

### In Scope

- API tokens issued at **default scope** and at **store-view scope** (multi-tenant per store-view). Each scope has at most one active token.
- **Partition semantics, not cascade:** the default-scope token is restricted to stores that do **not** have their own dedicated token; a store-view-scoped token is restricted to that single store-view. A merchant with two store-views can issue separate tokens for each, isolating their data from the default-scope token's view.
- **Allow-list of scope-aware REST resources.** Token-authenticated calls succeed only against REST resources for which the module has installed a per-store filter plugin. Initial coverage: `Magento_Sales::actions_view` (orders) and `MyParcelNL_Magento::delivery_options_read`. Resources that are granted to the integration but not in the allow-list return `401` for token-authenticated calls (admin and customer auth paths are unaffected).
- Admin-driven generation, one-time display, rotation, and revocation from the existing MyParcel admin config screen — per scope. Each scope's token is managed independently.
- REST-side authentication using a custom `Authorization` scheme.
- Reuse of Magento's `authorization_role` / `authorization_rule` ACL tables for per-resource gating (install-wide), layered with the module's own per-store filter enforcement.

### Out of Scope

- **Website-scope tokens.** Only default and store-view scopes are supported. Issuing a token at website scope is rejected by the admin controller.
- **Token TTL / expiry on day one.** Rotation is the operator's lever.
- **Frontend (customer-facing) API access.** This BR covers backoffice→Magento auth only.
- **Alternative `Authorization` headers** (e.g., `X-MyParcel-Token`). The standardized `Authorization` header is reused.
- **URL-prefix-driven store scoping for token-authenticated calls.** The token dictates scope; the `/rest/{store_code}/V1/...` URL prefix is decorative for token-authenticated calls and does not widen access.

## Success Criteria

- [ ] Median admin time-to-token (from opening the MyParcel admin config to a working token in the MyParcel backoffice) is under 30 seconds — for the default-scope token and for any store-view-scoped token.
- [ ] Zero "how do I activate the integration" support tickets in the 90 days following the release that ships this capability.
- [ ] On a fresh install, a customer can complete connection without running a single CLI command and without opening *System → Extensions → Integrations*.
- [ ] Rotating any scope's token invalidates that scope's previous token immediately, with no admin CLI step. Rotating one scope's token never invalidates other scopes' tokens.
- [ ] A store-view-scoped token can read only that store-view's orders and delivery options. The default-scope token can read orders and delivery options from all stores **except** stores that have their own dedicated token. Revoking a store-view's token cascades that store back into the default-scope token's view on the next request (subject to config cache invalidation).
- [ ] Token-authenticated calls against any granted REST resource without a corresponding per-store filter plugin return `401`, not unfiltered data.

## Stakeholders

| Role | Name | Responsibility |
|---|---|---|
| Business Sponsor | MyParcel platform team | Funds and prioritises this initiative |
| Product Owner | MyParcel platform PM | Requirements refinement, acceptance |
| Technical Lead | MyParcel Magento module maintainer | Feasibility, ADR-equivalent design notes (see TR-000004 §Rationale) |
| End Users | MyParcel customers (Magento shop admins) | Validation; reduced onboarding friction |
| Consumer | MyParcel SaaS backoffice | Calls customer Magento REST endpoints with the issued token |

## Constraints

- **Technical:** Must not modify any `vendor/magento/**` file. Must not require any change to `oauth/consumer/enable_integration_as_bearer`. Must coexist with Magento's native `Bearer` scheme so other modules and admin tokens continue to work unchanged.
- **Compliance:** The token is encryption-key-independent (stored as a SHA-256 hash, see TR-000004), so neither MyParcel nor anyone with database read access can recover plaintext after issuance.
- **Compatibility:** Must work on Magento 2.4.4+ regardless of the bearer-gate flag value.

## Dependencies

- Magento 2.4.4+ (target install base of the module).
- The MyParcel SDK / backoffice already supports a custom-scheme `Authorization` header per BR.

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Magento bumps the bearer-gate flag default again or removes the integration auto-provisioning hook | Low | Medium | Pin the auto-provisioning behaviour in TR-000004 §Specifications; integration tests cover both flag values. |
| Customer rotates Magento `crypt/key` and breaks existing tokens | N/A | None | Storage is hashed, not encrypted — `crypt/key` rotation has zero effect on any scope's token (see TR-000004 §Rationale). |
| Admin accidentally activates the auto-provisioned integration in *Integrations* | Low | Low | Admin config `<comment>` tells them not to; integration is harmless even if activated (no OAuth token issued). |
| New ACL grant added to `etc/integration.xml` without a corresponding per-store filter plugin → cross-store data leak for token-authenticated calls | Medium | High | Allow-list (`ScopedResourceRegistry`) fails closed: granted-but-unregistered resources return `401` for token-authenticated calls. Code-review checklist + a regression test enumerate registry vs. integration.xml grants. See TR-000004. |
| Adding/removing a store-scoped token does not propagate before next request because of `core_config_data` cache | Low | Medium | Generate / clear flow flushes the relevant config cache type explicitly. Verified end-to-end (see TR-000004 §Verification). |
| Token-authenticated caller assumes `/rest/{store_code}/V1/...` URL prefix widens scope | Low | Low | Documented as decorative for token auth; integration test covers an attempted bypass via mismatched URL prefix. |

## Approval

| Role | Name | Date | Status |
|---|---|---|---|
| Business Sponsor | | | Pending |
| Product Owner | | | Pending |

## Traceability

- **Implements:** —
- **Decomposed into Functional Requirements:** [FR-000005 — Self-service API token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-token.md)
- **Technical Requirements:** [TR-000004 — REST API Token Authentication](../technical-requirements/TR-000004-rest-api-token-authentication.md)