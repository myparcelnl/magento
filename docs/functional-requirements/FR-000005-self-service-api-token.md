# FR-000005: Self-Service API Token for MyParcel REST Integration

## Parent Requirement

- **Business Requirement:** [BR-000002 — MyParcel backoffice authenticates against customer Magento REST API](../business-requirements/BR-000002-myparcel-backoffice-rest-auth.md)
- **Related User Stories:**
    - [US-000001 — Admin generates API token](../user-stories/US-000001-admin-generates-api-token.md)
    - [US-000002 — Admin rotates API token](../user-stories/US-000002-admin-rotates-api-token.md)
    - [US-000003 — Admin revokes API token](../user-stories/US-000003-admin-revokes-api-token.md)
    - [US-000004 — REST caller authenticates with token](../user-stories/US-000004-rest-caller-authenticates-with-token.md)

## Description

The system MUST allow a Magento admin to **generate**, **view (one-time)**, **rotate**, and **revoke** API tokens for the MyParcel backoffice from the existing MyParcel admin config screen, without:

- running any `bin/magento` command,
- interacting with *System → Extensions → Integrations*,
- or modifying any Magento configuration flag.

A token, once generated, authenticates incoming Magento REST requests that present it via a custom `Authorization` scheme. Per-resource access reuses Magento's native ACL machinery (install-wide grants on the auto-provisioned integration); per-store data filtering is enforced by the module after authentication.

The system supports two scope levels: **default** and **store-view**. Each scope holds at most one active token. Issuing a token at website scope is rejected.

The scoping model is partition-based, not cascade-based:

- A token issued at a **store-view** scope authenticates calls only for that single store-view's data.
- The **default-scope** token authenticates calls only for stores that do **not** have their own dedicated token. Issuing a store-view-scoped token therefore *removes* that store-view from the default token's view; revoking the store-view-scoped token rejoins it.
- The `/rest/{store_code}/V1/...` URL prefix is **decorative** for token-authenticated calls — the token alone determines which stores' data is returned.

Token-authenticated calls succeed only against REST resources for which the module has installed a per-store filter plugin (the *scoped-resource allow-list*). Resources granted to the integration but not in the allow-list return `401` for token-authenticated callers. Admin and customer auth paths are unaffected.

## User Impact

- **Magento shop admin (primary):** Connects their store to MyParcel via a single button click. No CLI knowledge required, no OAuth handshake to step through, no risk of misconfiguring the wrong integration. On a multi-store install, the admin can switch the scope selector to a specific store-view and issue a separate token bound to that store-view. Can rotate or revoke any of these tokens independently from the same screen.
- **MyParcel backoffice service (consumer):** Authenticates to the customer's Magento REST API using the token in the `Authorization` header — no OAuth flow, no token refresh, no per-customer wiring. The scope of returned data is implicit in the token and requires no extra request parameters.

## Acceptance Criteria

### Provisioning & generation

- [ ] Running `bin/magento setup:upgrade` on a fresh install auto-provisions the supporting integration record and ACL grants — no further admin action is required before token generation.
- [ ] The MyParcel admin config screen exposes an *API Access* group with a single *Generate* button. The group is visible at **default scope** and at any **store-view scope**, and **hidden at website scope**.
- [ ] Clicking *Generate* in the current scope displays a freshly issued plaintext token exactly once. After navigating away or reloading, the field shows a masked placeholder; the plaintext is no longer recoverable from the UI or storage.
- [ ] Clicking *Generate* a second time at the same scope issues a new token; the previous token at that same scope is rejected on its very next REST call. Tokens at other scopes are unaffected.
- [ ] An attempt to generate or persist a token at website scope (e.g., via the controller endpoint with `scope=websites`) is rejected with `400`.

### Authentication & filtering

- [ ] A REST caller presenting `Authorization: MyParcel <correct-token>` for a token issued at **store-view S** receives only data belonging to store-view S, regardless of the URL prefix (`/rest/V1/...`, `/rest/default/V1/...`, `/rest/<other_store_code>/V1/...`).
- [ ] A REST caller presenting `Authorization: MyParcel <correct-token>` for a token issued at **default scope** receives data from all stores **except** any store-view that has its own dedicated token at the moment of the request (subject to config cache invalidation on token writes).
- [ ] Single-record retrieval of an out-of-scope record (e.g., `GET /V1/orders/{id}` where the order's store is outside the token's scope) returns `404`, not `403` (no existence leak).
- [ ] A REST caller presenting a valid token against an integration-granted ACL resource that is **not** in the scoped-resource allow-list returns `401`. Admin and customer auth paths against the same resource are unaffected.
- [ ] The custom auth scheme is case-insensitive (`MyParcel`, `myparcel`, `MYPARCEL` all match).

### Lifecycle isolation

- [ ] Rotating the default-scope token does not affect any store-view-scoped token. Rotating a store-view-scoped token does not affect the default-scope token or any other store-view-scoped token.
- [ ] Revoking (clearing) a store-view-scoped token causes that store-view's data to rejoin the default-scope token's view on the very next request (after config cache invalidation). Revoking the default-scope token has no effect on store-view-scoped tokens.
- [ ] All REST calls presenting a revoked token value fail with `401`.

### Compatibility

- [ ] Magento's native `Bearer` token auth, admin tokens, and other modules' integrations continue to work unchanged with this feature installed.
- [ ] The capability survives `setup:upgrade` re-runs (idempotent provisioning) and Magento minor-version upgrades.

## Priority

**Classification:** Must Have

**Justification:** Several planned MyParcel features that consume Magento order data via REST cannot ship without a low-friction authentication mechanism.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000004 — REST API Token Authentication](../technical-requirements/TR-000004-rest-api-token-authentication.md) — defines the security/compatibility/performance criteria, the verification scenarios, and the cross-cutting design rationale (custom UserContext bypassing the bearer gate; default-scope-only single-tenant model).

### Referenced Architectural Decisions

This module does not maintain a separate `docs/architectural-decisions/` directory. Cross-cutting design decisions live in TR-000004 §Rationale and §Constraints. Local class-level decisions are documented as PHPDoc / XML comments on the implementation files (`src/Model/Authorization/ApiTokenUserContext.php`, `src/Service/ApiTokenManager.php`, `etc/integration.xml`) — see TR-000004 §Implementation notes for the contract those comments must carry.

### Notes

The implementation reuses Magento's existing integration auto-provisioning mechanism (`Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations` → `grantPermissions`), so the module ships an `etc/integration.xml` declaring an inactive integration named "MyParcel API". A token authenticates as `USER_TYPE_INTEGRATION` against this integration's id, reusing Magento's native ACL enforcement at `Magento\Webapi\Controller\Rest\RequestValidator::checkPermissions`.

Per-store filtering is layered on top of native auth, after `RequestValidator` accepts the request. Two enforcement paths cooperate:

- A `MyParcelTokenAclGate` plugin on `RequestValidator` rejects token-authenticated calls whose ACL resource is not in the module's *scoped-resource allow-list* (`ScopedResourceRegistry`). This prevents new ACL grants from leaking data across stores until a corresponding filter plugin is wired.
- An `OrderRepositoryStoreFilter` plugin on `Magento\Sales\Api\OrderRepositoryInterface` injects the token's permitted `store_id` set into `getList` SearchCriteria and rejects out-of-scope `get(id)` lookups with `NoSuchEntityException` (→ `404`). The module's own versioned endpoints in `src/Model/Rest/` apply the same permitted-set check before exposing order-derived data.

## Dependencies

### Upstream (this FR depends on)

- Magento 2.4.4+ baseline.
- Existing `MyParcelNL_Magento::myparcelnl_magento` ACL resource (admin must have permission to view/edit the MyParcel config section to use this feature).

### Downstream (depends on this FR)

- Future MyParcel→Magento data-sync features that consume `GET /V1/orders` and `GET /V1/myparcel/delivery-options`.

## Cross-References

- **Also enables:** future REST endpoints declared by the module — granting a new ACL resource in `etc/integration.xml` (and bumping module version to re-run `grantPermissions`) is sufficient to extend access without re-issuing tokens.

## Implementation Notes

The full implementation contract (file layout, class responsibilities, scope pinning, hashing primitive) lives in TR-000004 §Specifications and §Implementation notes. Per-class design rationale will land as PHPDoc / XML comments on the source files when they are created. No standalone ADR documents will be authored.
