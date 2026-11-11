# FR-000005: Self-Service API Access Token for MyParcel REST Integration

## Parent Requirement

- **Business Requirement:** [BR-000002 — MyParcel backoffice authenticates against customer Magento REST API](../business-requirements/BR-000002-myparcel-backoffice-rest-auth.md)
- **Related User Stories:**
    - [US-000001 — Admin generates API access token](../user-stories/US-000001-admin-generates-api-access-token.md)
    - [US-000002 — Admin rotates API access token](../user-stories/US-000002-admin-rotates-api-access-token.md)
    - [US-000003 — Admin revokes API access token](../user-stories/US-000003-admin-revokes-api-access-token.md)
    - [US-000004 — REST caller authenticates with token](../user-stories/US-000004-rest-caller-authenticates-with-token.md)
    - [US-000005 — Admin generates store-scoped API access token](../user-stories/US-000005-admin-generates-store-scoped-api-access-token.md)
    - [US-000006 — Admin generates website-scoped API access token](../user-stories/US-000006-admin-generates-website-scoped-api-access-token.md)

## Description

The system MUST allow a Magento admin to **generate**, **view (one-time)**, **rotate**, and **revoke** API access tokens for the MyParcel backoffice from the existing MyParcel admin config screen, without:

- running any `bin/magento` command,
- interacting with *System → Extensions → Integrations*,
- or modifying any Magento configuration flag.

A token, once generated, authenticates incoming Magento REST requests that present it via a custom `Authorization` scheme. Per-resource access reuses Magento's native ACL machinery (install-wide grants on the auto-provisioned integration); per-store data filtering is enforced by the module after authentication.

The system supports three scope levels: **default**, **website**, and **store-view**. Each `(scopeType, scopeId)` coordinate holds at most one active token. Other Magento scope codes (custom group scopes, typos, future additions) are rejected at the controller with `400`.

The scoping model is partition-based, not cascade-based — but it borrows Magento's precedence order (`stores > websites > default`) to assign **one owning row per store**:

- A token issued at a **store-view** scope authenticates calls only for that single store-view's data.
- A token issued at a **website** scope authenticates calls for every store-view in that website that does **not** have its own dedicated store-view token. Issuing a store-view token within a website *carves* that store-view out of the website token's view; revoking it rejoins.
- The **default-scope** token authenticates calls for every store that has neither a dedicated store-view token nor a parent-website token. Issuing a website-scope or store-view-scope token *removes* the affected stores from the default token's view; revoking finer tokens releases their stores back to the next-coarsest tier that still has a token.
- Membership is determined by **row coordinates** (which row owns each store), not by hash equality at the membership step — duplicate hashes across rows cannot conflate ownership. A defensive `409 Conflict` at write time still rejects any attempt to persist a hash that already exists at another scope coordinate.
- The `/rest/{store_code}/V1/...` URL prefix is **decorative** for token-authenticated calls — the token alone determines which stores' data is returned.

Token-authenticated calls succeed only against REST resources for which the module has installed a per-store filter plugin (the *scoped-resource allow-list*). Resources granted to the integration but not in the allow-list return `401` for token-authenticated callers. Admin and customer auth paths are unaffected.

## User Impact

- **Magento shop admin (primary):** Connects their store to MyParcel via a single button click. No CLI knowledge required, no OAuth handshake to step through, no risk of misconfiguring the wrong integration. On a multi-website / multi-store install, the admin can switch the scope selector to a specific website or store-view and issue a separate token bound to that scope coordinate; finer-grained tokens automatically carve their stores out of any parent token's view. Can rotate or revoke any of these tokens independently from the same screen.
- **MyParcel backoffice service (consumer):** Authenticates to the customer's Magento REST API using the token in the `Authorization` header — no OAuth flow, no token refresh, no per-customer wiring. The scope of returned data is implicit in the token and requires no extra request parameters.

## Acceptance Criteria

### Provisioning & generation

- [ ] Running `bin/magento setup:upgrade` on a fresh install auto-provisions the supporting integration record and ACL grants — no further admin action is required before token generation.
- [ ] The MyParcel admin config screen exposes an *API Access* group with a single *Generate* button. The group is visible at **default**, **website**, and **store-view** scope.
- [ ] Clicking *Generate* in the current scope displays a freshly issued plaintext token exactly once. After navigating away or reloading, the field shows a masked placeholder; the plaintext is no longer recoverable from the UI or storage.
- [ ] Clicking *Generate* a second time at the same scope issues a new token; the previous token at that same scope is rejected on its very next REST call. Tokens at other scope coordinates are unaffected.
- [ ] An attempt to generate or persist a token at any unsupported scope (e.g., a custom group scope) is rejected with `400`.
- [ ] An attempt to generate a token whose hash already exists at another scope coordinate is rejected with `409 Conflict` and a clear admin-visible message; no row is written.

### Authentication & filtering

- [ ] A REST caller presenting `Authorization: MyParcel <correct-token>` for a token issued at **store-view S** receives only data belonging to store-view S, regardless of the URL prefix (`/rest/V1/...`, `/rest/default/V1/...`, `/rest/<other_store_code>/V1/...`).
- [ ] A REST caller presenting `Authorization: MyParcel <correct-token>` for a token issued at **website W** receives data from every store-view in W that does **not** have its own dedicated store-view token at the moment of the request (subject to config cache invalidation on token writes).
- [ ] A REST caller presenting `Authorization: MyParcel <correct-token>` for a token issued at **default scope** receives data from every store-view that has neither a parent-website token nor its own dedicated token at the moment of the request.
- [ ] A disabled store-view remains in its owning token's view; existing orders for that store-view are queryable. (No new orders are produced for a disabled store, so no new data is exposed beyond what the owning token already covered.)
- [ ] Single-record retrieval of an out-of-scope record (e.g., `GET /V1/orders/{id}` where the order's store is outside the token's scope) returns `404`, not `403` (no existence leak).
- [ ] A REST caller presenting a valid token against an integration-granted ACL resource that is **not** in the scoped-resource allow-list returns `401`. Admin and customer auth paths against the same resource are unaffected.
- [ ] The custom auth scheme is case-insensitive (`MyParcel`, `myparcel`, `MYPARCEL` all match).

### Lifecycle isolation

- [ ] Rotating a token at any scope coordinate does not affect tokens at other scope coordinates.
- [ ] Revoking (clearing) a token releases its stores back to the next-coarsest tier that still has a token: revoking a store-view-scoped token rejoins that store to its parent website token (or the default token, if there is no website token); revoking a website-scoped token rejoins its still-non-store-tokened stores to the default token (or to "no token covers this store" if no default token exists). Revoking the default-scope token has no effect on website- or store-view-scoped tokens.
- [ ] All REST calls presenting a revoked token value fail with `401`.

### Compatibility

- [ ] Magento's native `Bearer` token auth, admin tokens, and other modules' integrations continue to work unchanged with this feature installed.
- [ ] The capability survives `setup:upgrade` re-runs (idempotent provisioning) and Magento minor-version upgrades.

## Priority

**Classification:** Must Have

**Justification:** Several planned MyParcel features that consume Magento order data via REST cannot ship without a low-friction authentication mechanism.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000004 — REST API Access Token Authentication](../technical-requirements/TR-000004-rest-api-access-token-authentication.md) — defines the security/compatibility/performance criteria, the verification scenarios, and the cross-cutting design rationale (custom UserContext bypassing the bearer gate; three-tier partition scoping).

### Referenced Architectural Decisions

This module does not maintain a separate `docs/architectural-decisions/` directory. All design rationale lives in TR-000004 §Rationale and §Constraints. Class-level docblocks on the implementation files carry only what a reader cannot get from the code itself.

### Notes

The implementation reuses Magento's existing integration auto-provisioning mechanism (`Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations` → `grantPermissions`), so the module ships an `etc/integration.xml` declaring an inactive integration named "MyParcel API". A token authenticates as `USER_TYPE_INTEGRATION` against this integration's id, reusing Magento's native ACL enforcement at `Magento\Webapi\Controller\Rest\RequestValidator::checkPermissions`.

Per-store filtering is layered on top of native auth, after `RequestValidator` accepts the request. Two enforcement paths cooperate:

- A `MyParcelTokenAclGate` plugin on `RequestValidator` rejects token-authenticated calls whose ACL resource is not in the module's *scoped-resource allow-list* (`ScopedResourceRegistry`). This prevents new ACL grants from leaking data across stores until a corresponding filter plugin is wired.
- An `OrderRepositoryStoreFilter` plugin on `Magento\Sales\Api\OrderRepositoryInterface` injects the token's permitted `store_id` set into `getList` SearchCriteria and rejects out-of-scope `get(id)` lookups with `NoSuchEntityException` (→ `404`). The permitted set is computed by `TokenScopeContext::permittedStoreIds()` from a single bulk SELECT against `core_config_data` plus the `store→website` map from `StoreManagerInterface`; ownership is decided by **row coordinates** (`stores > websites > default`), not by re-hashing or by value-comparing cascaded reads. The module's own versioned endpoints in `src/Model/Rest/` apply the same permitted-set check before exposing order-derived data.

## Dependencies

### Upstream (this FR depends on)

- Magento 2.4.4+ baseline.
- Existing `MyParcelNL_Magento::myparcelnl_magento` ACL resource (admin must have permission to view/edit the MyParcel config section to use this feature).

### Downstream (depends on this FR)

- Future MyParcel→Magento data-sync features that consume `GET /V1/orders` and `GET /V1/myparcel/delivery-options`.

## Cross-References

- **Also enables:** future REST endpoints declared by the module — granting a new ACL resource in `etc/integration.xml` (and bumping module version to re-run `grantPermissions`) is sufficient to extend access without re-issuing tokens.

## Implementation Notes

The full implementation contract (file layout, class responsibilities, scope pinning, hashing primitive) lives in TR-000004 §Specifications and §Implementation notes. Design rationale lives in TR-000004 §Rationale and §Constraints rather than in per-class docblocks. No standalone ADR documents will be authored.
