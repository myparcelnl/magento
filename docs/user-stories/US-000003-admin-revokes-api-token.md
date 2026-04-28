# US-000003: Admin Revokes API Token

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-token.md)

## Story

As a **Magento shop admin**,
I want **to remove a MyParcel API token at a specific scope (default or store-view)**,
So that **MyParcel can no longer call my Magento REST API at that scope until I deliberately re-issue a token, while tokens at other scopes keep working**.

## Acceptance Criteria

### Scenario 1: Cleared token at scope `S` rejects callers presenting that token

**Given** a token T has been generated at scope `S` and is in active use,
**When** the storage row for scope `S` is cleared (mechanism: see Technical Notes),
**Then** any subsequent REST call presenting `Authorization: MyParcel <T>` returns `401 Unauthorized`,
**And** the rejection happens because no stored hash matches at any scope (the SELECT against `core_config_data` returns zero rows for that hash), not because of a string mismatch at a specific scope.

### Scenario 2: Tokens at other scopes continue to work after revocation at scope `S`

**Given** the install has both a default-scope token `T_default` and a store-view-scoped token `T_s2`,
**When** I revoke **only** `T_s2`,
**Then** subsequent calls with `T_default` continue to succeed,
**And** subsequent calls with the prior `T_s2` return `401`.

**Given** the same setup,
**When** I revoke **only** `T_default`,
**Then** subsequent calls with `T_s2` continue to succeed and still return only store-2 records,
**And** subsequent calls with the prior `T_default` return `401`.

### Scenario 3: Revoking a store-view-scoped token cascades that store back into the default-scope view

**Given** the install has both `T_default` and `T_s2`, and (per TR-000004's partition rule) `T_default` therefore returns orders from all stores **except** store 2,
**When** I revoke `T_s2`,
**And** the config cache type covering `core_config_data` reads is flushed (which the `clear()` flow does automatically),
**Then** the very next call with `T_default` returns orders from all stores **including** store 2.

### Scenario 4: Re-generating at the same scope after revocation issues a fresh token

**Given** the token at scope `S` has been revoked and the storage row at `S` is gone,
**When** the admin views the *API Access* group at scope `S` and clicks *Generate*,
**Then** a new token is issued and displayed exactly once,
**And** subsequent REST calls with that token succeed for the resources covered by `ScopedResourceRegistry`, scoped to `S`.

### Scenario 5: Revocation does not break other authentication

**Given** any MyParcel API token has been revoked,
**When** an admin user authenticates with `Authorization: Bearer <admin-token>`,
**Or** other modules' integrations use their own bearer tokens,
**Then** those requests continue to succeed unchanged.

### Scenario 6: Revocation does not remove the auto-provisioned integration

**Given** any MyParcel API token has been revoked,
**When** the admin opens *System → Extensions → Integrations*,
**Then** the inactive "MyParcel API" integration entry is still listed (its ACL grants stay intact),
**And** generating a new token on the *API Access* screen at any supported scope will re-attach to the same integration without requiring another `setup:upgrade`.

## Story Points

**Estimate:** 2
**Complexity:** Low

## Technical Notes

- The mechanism for revocation must be finalized during this story's design. Two reasonable options:
    - **(a) UI button:** add a *Revoke* button to the *API Access* group alongside *Generate* at the current scope. Pros: matches the self-service spirit; lets the admin express "no token at this scope at all". Cons: extra UI affordance, slight ACL surface increase.
    - **(b) Implicit via Generate:** rely on the rotation flow (US-000002). To "revoke", the admin generates a new token and discards it. The previous token is invalidated immediately. Pros: zero new UI. Cons: a hash always exists in storage at that scope; "no token at all at this scope" is not directly expressible from the UI, which means cascade-back behaviour (Scenario 3) is unreachable through the UI.
    - Decide during implementation. Given the partition + cascade-back semantics, option **(a)** is preferred — only an explicit clear allows a store's data to rejoin the default token's view.
- The technical primitive is `ApiTokenManager::clear($scopeType, $scopeId)` which deletes the row at that scope via `WriterInterface::delete($path, $scopeType === 'default' ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : ScopeInterface::SCOPE_STORES, $scopeId)` and flushes the config cache type so the partition rule sees the change immediately.
- Per TR-000004 §Specifications "Revocation isolation + cascade-back" criterion: clearing the row at scope `S` causes calls with the prior token to return `401`; if `S` was a store-view scope, that store's data rejoins the default token's view on the next request.

## Dependencies

- Builds on US-000001 (storage layer).
- If option (a) is chosen, this story adds a UI control; otherwise no new UI.

## Definition of Done

- [ ] Decision recorded (option a or b) and reflected in `etc/adminhtml/system.xml` group definition.
- [ ] Integration test: clearing scope `S`'s row at default scope causes the prior `T_default` to return `401`.
- [ ] Integration test: clearing a store-view scope's row causes the prior `T_s2` to return `401` AND the next call with `T_default` rejoins that store-view's data into its result set.
- [ ] Integration test: re-generating at any supported scope after revocation works end-to-end without `setup:upgrade`.
- [ ] If option (a) chosen: ACL entry for the revoke action; controller is POST-only and accepts only `scope=default` or `scope=stores` (mirrors US-000001 controller); rejects website scope and any unknown scope with `400`.
