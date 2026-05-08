# US-000002: Admin Rotates API Access Token

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Story

As a **Magento shop admin**,
I want **to rotate the MyParcel API access token at any specific scope coordinate (default, a specific website, or a specific store-view) when I think it has been exposed**,
So that **I can revoke access for the previous token at that scope coordinate immediately, without disturbing tokens at other scope coordinates or other authentication mechanisms in my store**.

## Acceptance Criteria

### Scenario 1: Regenerating at the current scope issues a new token and displays it once

**Given** a token T1 has been generated previously at scope coordinate `S` (where `S` is `(default, 0)`, `(websites, websiteId)`, or `(stores, storeId)`),
**And** I am viewing the *API Access* group at scope `S`,
**When** I click *Generate* again,
**Then** a new token T2 is displayed in full exactly once,
**And** the `core_config_data` row at scope `S` and path `myparcelnl_magento_general/api_access_token` now contains the SHA-256 hash of T2 (and no longer the hash of T1).

### Scenario 2: Previous token at the rotated scope is rejected immediately

**Given** I have just rotated to T2 at scope `S`,
**When** the MyParcel backoffice (or any caller) presents `Authorization: MyParcel <T1>` to a previously-working REST endpoint,
**Then** the response is `401 Unauthorized`,
**And** no order or delivery-options data is returned.

### Scenario 3: New token at the rotated scope works immediately and respects scope filtering

**Given** I have just rotated to T2 at scope `S`,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T2>` to the same REST endpoint,
**Then** the response is `200 OK` with the expected payload, filtered to the same scope `S` as before the rotation,
**And** native ACL + the scoped-resource allow-list still apply (resources outside the integration's grants OR outside `ScopedResourceRegistry` return `401`).

### Scenario 4: Tokens at other scopes are unaffected

**Given** the install has both a default-scope token `T_default` and a store-view-scoped token `T_s2`,
**When** I rotate **only** `T_default` to `T_default'`,
**Then** subsequent calls with `T_s2` still return `200` for store-view 2's data,
**And** subsequent calls with the previous `T_default` return `401`,
**And** subsequent calls with `T_default'` return `200` for all stores except those with their own dedicated token (per the partition rule).

**Given** the same setup,
**When** I rotate **only** `T_s2` to `T_s2'`,
**Then** subsequent calls with `T_default` still return their previous result set unchanged,
**And** subsequent calls with the previous `T_s2` return `401`,
**And** subsequent calls with `T_s2'` return `200` for store-view 2's data.

### Scenario 5: Other authentication mechanisms are unaffected

**Given** I have just rotated the MyParcel token at any scope,
**When** an admin user authenticates with a Magento admin token via `Authorization: Bearer <admin-token>`,
**Or** another module's integration uses its own `Authorization: Bearer` token,
**Then** those requests succeed exactly as before — the rotation only affects MyParcel's custom-scheme token at the rotated scope.

## Story Points

**Estimate:** 1
**Complexity:** Low

## Technical Notes

- Rotation is `ApiAccessTokenManager::generate($scopeType, $scopeId)` called a second time at the same `(scopeType, scopeId)`. Because storage is the same `core_config_data` row, writing the new hash overwrites the old one atomically and the config cache is flushed before the response returns.
- Per TR-000004 §Specifications "Rotation isolation" criterion: rotation at scope `S` MUST NOT touch any other scope's row.
- The partition rule (TR-000004 §Specifications "Scope partitioning") is independent of rotation: rotating any scope's token does not change ownership at any other tier, because ownership is computed from row *existence* at `(scope, scope_id)` coordinates, not from token values. Rotating overwrites the hash at one coordinate; ownership of every store stays exactly where it was.
- Adds the hash-uniqueness invariant: a rotation that happens to produce the same hash as another scope's existing row is rejected with `409 Conflict` (extremely unlikely cryptographically; defended for operator-test-seam scenarios).

## Dependencies

- Builds on US-000001 (the Generate flow). No additional UI surface required.

## Definition of Done

- [ ] Integration test covers the rotation scenario at default scope: generate → capture T1 → generate → T1 returns 401, T2 returns 200.
- [ ] Integration test covers the rotation scenario at a website scope and at a store-view scope, plus cross-scope isolation: rotating any one scope coordinate's token does not touch any other scope coordinate's token (any of the other 2 tiers).
- [ ] Native `Bearer` auth and other modules' integrations verified unaffected (regression check).
- [ ] Multi-store fixture verifies that rotating `T_default` while a `T_s2` exists does NOT change the partition rule's carve-out behaviour for either token.
- [ ] No additional admin documentation needed beyond the *API Access* group `<comment>` (which covers rotation: "To rotate, click Generate again; the previous token at this scope stops working immediately.").
