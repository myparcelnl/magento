# TR-000004: REST API Access Token Authentication

> **Status:** Draft — targeting branch `feat/rest-api-access-token-auth`. Revised 2026-04-28: auth scheme renamed `MyParcelNL` → `MyParcel`; API Access admin caveat copy added; storage primitive switched from `EncryptorInterface::encrypt` to one-way SHA-256 hashing; reorganized into BR-000002 / FR-000005 / US-000001..4 with cross-cutting design rationale retained in §Rationale and §Constraints below.
>
> **Revised 2026-04-28 (later same day):** the default-scope-only single-tenant constraint is **reversed** — tokens are now multi-tenant at default + store-view scopes with partition semantics (a store-view-scoped token shadows that store-view from the default token's view). A new *scoped-resource allow-list* enforces that token-authenticated calls only reach REST resources for which the module ships a per-store filter plugin. New supporting components: `TokenScopeContext`, `ScopedResourceRegistry`, `OrderRepositoryStoreFilter`, `MyParcelTokenAclGate`, plus a `permittedStoreIds()` accessor on `src/Model/Rest/AbstractEndpoint.php`. New US-000005 covers store-view-scoped generation.
>
> **Revised 2026-04-28 (third revision):** the **website** scope tier is added — supported scopes are now `default`, `websites`, and `stores`, each holding at most one active token. Partition semantics extend to a three-tier ownership rule (`stores > websites > default`): per store, the most-specific row that exists wins. Membership is computed by **row-coordinate** matching, not hash-value matching, so duplicate hashes across rows cannot conflate ownership. A hash-uniqueness invariant (rejects-409 on write-time conflict) defends against operator copy-paste. Disabled stores remain in their owning token's view (no security concern: the owning token must already exist, and a disabled store does not generate new orders). Admin store (`id=0`) is never tokened and never returned. New US-000006 covers website-scope generation; US-000005 Scenario 8 is replaced with a 3-tier carve-out scenario.

## Related Business Requirements

- [BR-000002 — MyParcel backoffice authenticates against customer Magento REST API](../business-requirements/BR-000002-myparcel-backoffice-rest-auth.md)

## Related Functional Requirements

- [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Related User Stories

- [US-000001 — Admin generates API access token](../user-stories/US-000001-admin-generates-api-access-token.md)
- [US-000002 — Admin rotates API access token](../user-stories/US-000002-admin-rotates-api-access-token.md)
- [US-000003 — Admin revokes API access token](../user-stories/US-000003-admin-revokes-api-access-token.md)
- [US-000004 — REST caller authenticates with token](../user-stories/US-000004-rest-caller-authenticates-with-token.md)
- [US-000005 — Admin generates store-scoped API access token](../user-stories/US-000005-admin-generates-store-scoped-api-access-token.md)
- [US-000006 — Admin generates website-scoped API access token](../user-stories/US-000006-admin-generates-website-scoped-api-access-token.md)

## Related ADRs

**No standalone ADRs.** This module does not maintain a `docs/architectural-decisions/` directory. Design rationale is split between:

- **Cross-cutting decisions** — captured in §Rationale (custom `UserContextInterface` bypassing Magento's bearer-token gate; partition vs. cascade scoping; allow-list deny-by-default) and §Constraints (scope-level support; per-store filter coverage) of this TR.
- **Local class-level decisions** — documented as PHPDoc / XML comments on the implementation files, written when those files are created. Required content (the contract):
    - `src/Model/Authorization/ApiAccessTokenUserContext.php` — why the class exists at all, why a custom `MyParcel` scheme, why `sortOrder=5`, why `USER_TYPE_INTEGRATION`, plus a reference table of `vendor/magento/**` files this class interacts with. Also: how the resolved token's scope is stored on `TokenScopeContext` for downstream filter plugins to read.
    - `src/Model/Authorization/TokenScopeContext.php` — why a separate value object (request-scoped, `ResetAfterRequestInterface`); why memoize the bulk row-set lookup; why ownership is computed by **row coordinates** (not hash equality) at the membership step — this is the structural defence against duplicate-hash conflation across scopes, and the reason the membership read joins the existing-rows set against the `store→website` map rather than re-hashing or value-comparing cascaded reads; why `permittedStoreIds()` is `null` for non-token requests (so the filter plugin no-ops); why disabled stores stay in the owning token's permitted set and admin store is excluded.
    - `src/Service/ApiAccessTokenManager.php` — why SHA-256 hashing instead of `EncryptorInterface::encrypt` (verify-only credential; encryption gives no defence against DB+filesystem read; decouples from `crypt/key`); why fast hash and not bcrypt/argon2 (256-bit random input → no dictionary attack surface); why `core_config_data` rows at multiple scopes (default + websites + stores); why no `getDecryptedToken()` method; why the hash-uniqueness invariant is enforced at write time (operator copy-paste defence — the controller surfaces a conflict as 409 with admin-visible message); why config cache flush is part of the generate/clear flow.
    - `src/Service/ScopedResourceRegistry.php` — why a deny-by-default allow-list (closes the leak risk of new ACL grants); why the registry is DI-configured (so future endpoints add themselves declaratively); the contract that a registry entry MUST be backed by a filter plugin.
    - `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` — why both `beforeGetList` and `afterGet` are needed; why `NoSuchEntityException` (404) and not `AuthorizationException` (403) for out-of-scope `get(id)`; why the plugin no-ops when `TokenScopeContext` is unset.
    - `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` — why this gate runs only for token-authenticated requests, why it sits as a plugin on `RequestValidator::validate`, why it returns 401 (not 403) when the resolved resource is outside the registry.
    - `etc/integration.xml` — why the integration is auto-provisioned and never activated; the three-step `grantPermissions` mechanism; how integration grants relate to the scoped-resource allow-list (grants are install-wide; per-store enforcement is layered on top).

## Category

Security (primary), Compatibility (secondary — Magento 2.4.4+ integration auth gate).

## Requirement

Customers of the Magento module MUST be able to authenticate a remote MyParcel backoffice caller to their Magento REST API by:

1. Running `bin/magento setup:upgrade` once (standard module upgrade step).
2. Clicking a single **Generate API access token** button in the MyParcel admin config.
3. Pasting the resulting token plus the shop URL into the MyParcel backoffice.

The solution MUST NOT require:

- Any `bin/magento config:set` step.
- Any interaction with Magento's *System → Extensions → Integrations* screen (activation, OAuth handshake, token retrieval).
- Any change to Magento's `oauth/consumer/enable_integration_as_bearer` configuration flag.

The solution MUST reuse Magento's native ACL machinery (`authorization_role`, `authorization_rule`, resource gating in `Magento\Webapi\Controller\Rest\RequestValidator::checkPermissions`) so that per-resource access follows the module's `etc/integration.xml` grants and survives future ACL resource additions without code changes.

## Rationale

MyParcel's SaaS backoffice needs to read from customer Magento REST endpoints — both native (`GET /V1/orders` etc., gated by `Magento_Sales::actions_view`) and our own custom endpoints (`GET /V1/myparcel/delivery-options`, gated by `MyParcelNL_Magento::delivery_options_read` on `feat/dedicated-delivery-options-endpoint`).

Magento 2.4.4+ added a gate at `vendor/magento/module-integration/Model/OpaqueToken/Reader.php:146` that disables Integration access tokens as REST bearer tokens by default (`oauth/consumer/enable_integration_as_bearer` = `0`). With that flag disabled, `UserTokenReader::read()` throws a silent `UserTokenException`, `TokenUserContext::processRequest()` (`vendor/magento/module-webapi/Model/Authorization/TokenUserContext.php:154-158`) bails, and no user is identified. The flag is only flippable via CLI or the OAuth admin page (`vendor/magento/module-integration/etc/adminhtml/system.xml:57`) — both of which violate the "admin does nothing technical" requirement.

We therefore sidestep the native bearer path and introduce our own `UserContextInterface` with a custom `Authorization` scheme, while reusing the rest of Magento's auth stack (integration records, ACL roles, rules, resource gating).

### Three-tier partition (row-coordinate ownership)

Magento's native scope system is *cascading*: a config value at store-view scope falls back to website, then to default. The token model **borrows the precedence order** (`stores > websites > default`) but applies it as a **partition**: per store `S`, exactly one token row "owns" `S` — the most-specific row that exists for `S` — and only that row's token authenticates calls that return `S`'s data. There is no fallback at request time.

This three-tier partition is required by the MyParcel side of the integration: a merchant who issues a separate token for store-view `S` must be able to bind that token to `S` exclusively (so its data is invisible to any sibling website-scope or default-scope token); a merchant who issues a website-scope token must cover every store-view in that website by default but retain the freedom to carve out individual store-views with their own tokens; and the default-scope token must cover everything not otherwise tokened. Cascading at request time would either leak shadowed stores back into a parent token's view or hide them from their owning token — neither is acceptable.

**Row-coordinate ownership (not hash-value equivalence).** Per store `S`, the owning row is determined by row *existence*, not hash equality:

- if a row exists at `(scope='stores', scope_id=S)` → owner is `(stores, S)`
- else if a row exists at `(scope='websites', scope_id=websiteOf(S))` → owner is `(websites, websiteOf(S))`
- else if a row exists at `(scope='default', scope_id=0)` → owner is `(default, 0)`
- else → no owner (no token covers `S`)

The presented token authenticates `S` iff the resolved `(scopeType, scopeId)` from the token-resolution step **equals** that owner tuple. This is structurally immune to duplicate-hash conflation: two rows holding the same hash by accident (or by operator copy-paste) cannot make the wrong store match, because the membership decision is based on row coordinates, not on hash equality at the membership step.

Implementation consequences:

- One bulk SELECT per request — `SELECT scope, scope_id FROM core_config_data WHERE path='myparcelnl_magento_general/api_access_token'` — yields the existing-rows set; cached on `TokenScopeContext` for the request lifetime via `ResetAfterRequestInterface`.
- Per-store ownership is computed in PHP from that set plus `StoreManagerInterface::getStores()` (yielding the `store→website` map; admin store id=0 is excluded by passing `withDefault=false`).
- A store-view-scoped token resolves to a single-element set `{scope_id}` (its `(stores, S)` row is its own owner).
- A website-scoped token resolves to "every store in the website **minus** stores with their own dedicated row".
- The default-scope token resolves to "every store **minus** stores in websites with a website row, **minus** stores with their own dedicated row".

**Disabled stores remain in their owning token's view.** A disabled store does not generate new orders and therefore cannot leak post-disablement data; pre-disablement orders remain queryable so the operator's mental model ("rotate or revoke explicitly; don't lose existing data silently") holds. Re-enabling a previously disabled store does not change ownership — the token already covered it.

**Admin store (id=0) is never tokened and never returned.** Token rows at `(scope='default', scope_id=0)` are the default-tier *token row*, not the admin-store data row; `StoreManagerInterface::getStores(false)` excludes admin from the candidate set, so no token's permitted set ever contains `0`.

### Allow-list (deny-by-default for non-filtered resources)

The earlier draft of this TR assumed any newly granted ACL resource in `etc/integration.xml` would "just work." Under per-store filtering, that property would silently leak data: a token at store-view S would receive *all* customers / all CMS pages / etc. unless a per-store filter plugin existed for each grant.

We close this by introducing a *scoped-resource allow-list* (`ScopedResourceRegistry`) and a `MyParcelTokenAclGate` plugin on `RequestValidator::validate`. Token-authenticated requests against any granted resource that is not in the registry return `401`. Adding a new ACL grant therefore requires three coordinated changes (grant in `etc/integration.xml`, filter plugin on the relevant repository, registry entry) — verified by a regression test that asserts every grant has a matching registry entry.

This is an explicit trade-off versus TR-000004's earlier "grant + go" forward-compat property: future ACL additions cost more, but cannot leak.

### Storage shape (multi-scope `core_config_data`)

Multi-scope `core_config_data` rows on path `myparcelnl_magento_general/api_access_token` (rather than a dedicated table) keep the storage primitive aligned with the rest of the module's config and the prior TR. Trade-offs:

- **Pro:** zero schema migration; reuses Magento's scope semantics (`scope` + `scope_id`); the admin UI's per-scope rendering follows Magento's standard config form.
- **Pro:** clear/generate writes integrate with Magento's config write API (which already invalidates the config cache type).
- **Con:** the partition query ("which `scope_id`s have a row?") goes via `\Magento\Framework\App\ResourceConnection` directly (not `ScopeConfigInterface`), since we don't know the scope of the presented token until after we've matched its hash.
- **Con:** mixes "configuration" and "credential storage" semantically. Mitigated by keeping the path under a dedicated `general/api_access_token` namespace and never reading the value through `ScopeConfigInterface`.

Baseline verified: `vendor/magento/module-webapi/Controller/Rest/RequestValidator.php:104` `checkPermissions` had a temporary `return;` that has been reverted; native ACL enforcement is working normally.

## Specifications

### Security Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Token entropy | 256 bits of OS-sourced randomness (`random_bytes(32)`) | Code inspection of `ApiAccessTokenManager::generate()` |
| Token presentation | Hex-encoded (64 lowercase hex chars) | Unit test: `generate()` output matches `/^[0-9a-f]{64}$/` |
| Storage hashing | Persisted as a SHA-256 hash (`hash('sha256', $token)` → 64 lowercase hex chars). Plaintext is never stored, never logged, never recoverable from storage. | Inspection of `core_config_data.value` for any row at path `myparcelnl_magento_general/api_access_token` — value is a 64-char hex SHA-256 digest of the issued token, NOT the token itself; reversing the digest is computationally infeasible. |
| Comparison | Constant-time via `hash_equals` | Code inspection of `ApiAccessTokenUserContext::processRequest()` |
| Plaintext exposure | Returned to admin exactly once, immediately after generation; never logged, never re-readable from storage | Unit test: `generate()` returns plaintext but config stores only the SHA-256 hash; admin UI on reload shows masked placeholder only; no server-side code path reads plaintext from storage. |
| Scheme | `Authorization: MyParcel <token>` (case-insensitive scheme) | Unit test: `Bearer`, empty, malformed, and other schemes all bail (`userType=null`). |
| ACL enforcement (install-wide) | Requests with valid token but resource not in integration grants return `401` | Integration test: token + `GET /V1/customers/1` (no `Magento_Customer::manage` grant) → `401`. |
| Allow-list enforcement (deny-by-default) | Requests with valid token against a granted ACL resource that is NOT in `ScopedResourceRegistry` return `401` (token-only); the same resource called with an admin bearer token continues to return `200` | Integration test: grant a "harness" ACL resource on the integration, do not register it in `ScopedResourceRegistry` → token call returns `401`; admin call returns `200`. |
| Rotation isolation | Generating a new token at scope `S` invalidates the previous token at scope `S` immediately and ONLY at scope `S`. Tokens at other scopes are unaffected. | Integration test: generate `T_default` and `T_s1` → re-generate `T_default` → previous default token returns `401`, `T_s1` still returns `200`. |
| Revocation isolation + cascade-back | Clearing the row at scope `S` causes subsequent requests with that token to return `401`; if `S` was a store-view scope, that store's data rejoins the default token's view on the next request. | Integration test: delete `core_config_data` row at `(stores, S)` manually → calls with the prior `T_s1` return `401`; calls with `T_default` now return that store's orders. |
| Scope partitioning (default-scope token) | When the presented token is bound to default scope, returned `store_id`s are every enabled non-admin store **minus** stores in any website with a website-scope row at `(path='myparcelnl_magento_general/api_access_token', scope='websites', scope_id=…)` **minus** stores with their own row at `(scope='stores', scope_id=…)`. | Integration test (multi-store + multi-website fixture): `T_default` returns orders from all stores; generate `T_W1` → `T_default` calls no longer return any store in W1; generate `T_s2` (s2 ∈ W1) → no further change to `T_default`'s view; revoke `T_W1` first then `T_s2` → all stores rejoin `T_default`. |
| Scope partitioning (website-scoped token) | When the presented token is bound to website `W`, returned `store_id`s are every enabled non-admin store in `W` **minus** stores in `W` with their own dedicated `(stores, S)` row. | Integration test: `T_W1` returns W1's stores; generate `T_s2` (s2 ∈ W1) → `T_W1` no longer returns store 2; revoke `T_s2` → store 2 rejoins `T_W1`'s view. |
| Scope partitioning (store-view-scoped token) | When the presented token is bound to store-view `S`, returned records ALL satisfy `store_id = S`, regardless of URL prefix. | Integration test: `T_s1` against `/rest/V1/orders`, `/rest/default/V1/orders`, `/rest/<other_code>/V1/orders` → all return only store-1 records. |
| Disabled-store inclusion | A store that is disabled at the moment of the request remains in its owning token's permitted set; its already-existing orders are queryable. New orders cannot be created for a disabled store, so this exposes no future data the owning token did not already cover. Re-enabling a disabled store does not change ownership. | Integration test: with `T_s2` issued and store 2 then disabled, `GET /V1/orders` with `T_s2` still returns store-2 orders. |
| Existence-leak prevention | Single-record retrieval of an out-of-scope record returns `404`, not `403`, so callers cannot probe existence by status code. | Integration test: `T_s1` requesting `GET /V1/orders/<id_in_store_2>` → `404`. |
| Storage scope acceptance | Generate / clear controller accepts `scope=default`, `scope=websites`, and `scope=stores`; rejects any other scope value (custom scope codes, typos) with `400`. | Unit test on `Controller/Adminhtml/ApiAccessToken/Generate.php`. |
| Hash uniqueness invariant | At write time, generating a token whose SHA-256 hash already exists at any other `(scope, scope_id)` row on the same path returns `409 Conflict` with a clear admin-visible message; no row is written. Defends against operator copy-paste of plaintext across scopes. | Unit test: pre-seed `(stores, 2)` with hash `H`; generate at `(default, 0)` and force its random bytes to produce `H` via a test seam → `409`. |

### Compatibility Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| UserContext registration | Custom context registered at `sortOrder=5` in `CompositeUserContext` chain, ahead of native `tokenUserContext` (`sortOrder=10`) | `bin/magento object-manager:debug Magento\Authorization\Model\CompositeUserContext` or equivalent inspection — `myParcelTokenUserContext` appears first in array. |
| Bypass safety when token absent | When no `Authorization: MyParcel` header is present, context returns `(userType=null, userId=null)`, `TokenScopeContext::permittedStoreIds()` is `null`, and downstream filter plugins no-op. The composite chain falls through to native contexts unchanged. | Unit test: request without our header → our context returns nulls; filter plugin's `beforeGetList` does NOT touch the SearchCriteria; native contexts process normally. |
| Native auth paths unaffected | Admin bearer tokens, customer tokens, and other modules' integrations continue to work unchanged on filtered AND non-filtered resources. The `MyParcelTokenAclGate` plugin only activates when our `UserContext` is the resolved one. | Integration test: admin bearer + `GET /V1/customers/1` → `200` even though `Magento_Customer::manage` is not in `ScopedResourceRegistry`. |
| URL prefix decorative for token auth | `/rest/{store_code}/V1/...` is honoured by `PathProcessor` (sets the StoreManager's current store) but does NOT widen or narrow what a token-authenticated call can read. | Integration test: `T_s1` against `/rest/<other_store_code>/V1/orders` → only store-1 records. |
| `/rest/all/V1/...` GET-blocked by Magento | Native Magento blocks `GET /rest/all/V1/...` (`vendor/magento/module-webapi/Controller/Rest.php:270-275`). Our scheme inherits this behaviour — no special handling required. | Integration test: `T_default` against `/rest/all/V1/orders` → Magento returns its native error, not unfiltered data. |
| Magento version | Works on Magento 2.4.4+ regardless of `oauth/consumer/enable_integration_as_bearer` value | Integration test: with flag both `0` (default) and `1`, our scheme works identically. |
| ACL grant mechanism | Integration role + rules created by Magento's own `Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations → grantPermissions` on `setup:upgrade` | Integration test: fresh install → `authorization_role` has row `(user_type=1, user_id=<integration id>)`; `authorization_rule` has rows for each resource in `etc/integration.xml`. |
| Allow-list ↔ integration.xml coverage | Every ACL resource granted in `etc/integration.xml` MUST appear in `ScopedResourceRegistry`. | Regression test: parse both files at boot, fail the suite if `etc/integration.xml` grants a resource that is not in the registry. |
| Forward-compatible grants — narrowed | Granting a resource that doesn't yet exist in any `acl.xml` is still a no-op at native ACL level (`grantPermissions` writes resource IDs as plain strings). However, the resource ALSO needs a `ScopedResourceRegistry` entry and a backing filter plugin to be reachable by token-authenticated calls. | Documented constraint; enforced by the regression test above. |

### Performance Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Per-request overhead — auth | Single header read + single `hash('sha256', $presented)` + single SELECT from `core_config_data` (`WHERE path=… AND value=… AND scope IN ('default','websites','stores')`) + single `IntegrationServiceInterface::findByName` (memoized). Hashing 64 input bytes is sub-microsecond. | Profile a typical request: auth-stage added latency < 2 ms at p95. |
| Per-request overhead — partition lookup | At most ONE additional SELECT from `core_config_data` per request that reaches a list endpoint, of the form `SELECT scope, scope_id FROM core_config_data WHERE path = 'myparcelnl_magento_general/api_access_token'` (no `value` filter). Yields the existing-rows set for all three tiers in one round trip. Memoized on `TokenScopeContext` for the request lifetime. Per-store ownership resolution is in-memory PHP from this set plus the `store→website` map from `StoreManagerInterface`. | Unit test: two `OrderRepositoryInterface::getList` calls in one request issue only one bulk row-set lookup. |
| Integration lookup | Memoized on class instance for the duration of the request (`ResetAfterRequestInterface`). | Unit test: multiple `processRequest()` calls within one request issue only one `findByName` query. |
| Allow-list lookup | In-memory hash-set check (`ScopedResourceRegistry`) — no DB roundtrip. | Code inspection. |
| Filter plugin overhead on getList | `beforeGetList` injects a single `Filter` into the existing SearchCriteria; no extra query. The added `WHERE store_id IN (…)` predicate is index-backed by `sales_order.IDX_SALES_ORDER_STORE_ID`. | Integration test on a fixture with N=10k orders: filtered getList latency within 5 % of unfiltered getList. |

## Verification Method

End-to-end via curl against a local dev store after the scaffold lands, plus unit coverage in `Tests/Unit/`.

### Test Scenarios

Multi-store + multi-website fixture: two websites — `W1` "Main Website" (`website_id=1`) containing `store_id=1` "default" and `store_id=2` "store_b"; `W2` "Secondary Website" (`website_id=2`) containing `store_id=3` "store_c" and `store_id=4` "store_d". Each store has at least one order. Admin store (`store_id=0`) is excluded from any token's view by construction.

1. **Install:** `bin/magento setup:upgrade && bin/magento cache:clean && bin/magento setup:di:compile` — no errors. Integration row appears in `integration`; ACL role + rules appear in `authorization_role` / `authorization_rule`.
2. **Admin generate flow (default scope):** Admin config page shows *API Access* group at default scope. Click **Generate** → token `T_default` shown in full once; `core_config_data` row at `(scope='default', scope_id=0, path='myparcelnl_magento_general/api_access_token')` contains a 64-char hex SHA-256 hash that does NOT equal `T_default`.
3. **Admin generate flow (website scope):** Switch scope selector to "Main Website" (W1) → *API Access* group is visible. Click **Generate** → token `T_W1` shown in full once; `core_config_data` row at `(scope='websites', scope_id=1, path='myparcelnl_magento_general/api_access_token')` contains a 64-char hex SHA-256 hash that does NOT equal `T_W1`.
4. **Admin generate flow (store-view scope):** Switch scope selector to "store_b" → *API Access* group is visible. Click **Generate** → token `T_s2` shown in full once; `core_config_data` row at `(scope='stores', scope_id=2, path='myparcelnl_magento_general/api_access_token')` contains a 64-char hex SHA-256 hash that does NOT equal `T_s2`.
5. **Unknown scope rejected:** Direct POST to the Generate controller with `scope=invalid&scopeId=1` returns `400`. Same with `scope=group&scopeId=1` (Magento group scope is not supported).
6. **Hash uniqueness rejected at write-time:** Force the random-bytes test seam to produce, at default-scope generation, the same hash already stored at `(stores, 2)` from scenario 4 → controller returns `409 Conflict` with a clear admin-visible message; no row written; no plaintext shown.
7. **Baseline unauthenticated:** `curl -i /rest/V1/orders` → `401`.
8. **Wrong scheme:** `curl -i -H "Authorization: Bearer anything" /rest/V1/orders` → `401`.
9. **Wrong token:** `curl -i -H "Authorization: MyParcel deadbeef" /rest/V1/orders` → `401`.
10. **Default token, no other tokens yet:** with only `T_default` issued, `curl -i -H "Authorization: MyParcel <T_default>" /rest/V1/orders?searchCriteria[pageSize]=10` → `200` with orders from stores 1, 2, 3, 4.
11. **Default token, after `T_W1` generated (website partition):** with `T_default` and `T_W1` issued, repeat scenario 10 → `200` with orders ONLY from stores 3 and 4 (W1's stores 1 and 2 are carved out by the website-tier row).
12. **Default token, after `T_W1` and `T_s2` generated (3-tier partition):** with `T_default`, `T_W1`, and `T_s2` issued, repeat scenario 10 → `200` with orders ONLY from stores 3 and 4 (unchanged from scenario 11; the store-tier row carves s2 out of `T_W1`'s view but not out of `T_default`'s view, which never owned s2 in the first place).
13. **Website token, before any store-tier carve-out:** `curl -H "Authorization: MyParcel <T_W1>" /rest/V1/orders` → orders from stores 1 and 2.
14. **Website token, after `T_s2` generated (store-tier carve-out within website):** with `T_s2` issued, repeat scenario 13 → `200` with orders ONLY from store 1.
15. **Store-view token narrows:** `curl -H "Authorization: MyParcel <T_s2>" /rest/V1/orders` → only store-2 orders.
16. **URL prefix is decorative:** `curl -H "Authorization: MyParcel <T_s2>" /rest/default/V1/orders` and `/rest/<store_c_code>/V1/orders` — both return only store-2 orders.
17. **Existence-leak prevention:** `curl -H "Authorization: MyParcel <T_s2>" /rest/V1/orders/<id_in_store_3>` → `404`.
18. **Custom endpoint with token scoping:** `curl -H "Authorization: MyParcel <T_s2>" "/rest/V1/myparcel/delivery-options?orderId=<id_in_store_3>"` → RFC 9457 404 (ProblemDetails).
19. **Allow-list rejects ungranted-but-scope-aware resources:** Grant a "harness" ACL resource to the integration without registering it in `ScopedResourceRegistry`. `curl -H "Authorization: MyParcel <T_default>" /rest/V1/<harness>` → `401`. Same call with admin bearer → `200` (allow-list applies only to token-authenticated calls).
20. **Allow-list parity with native ACL rejection:** `curl -H "Authorization: MyParcel <T_default>" /rest/V1/customers/1` → `401` (Magento_Customer::manage not granted; native ACL rejects).
21. **Rotation isolation:** click Generate at default scope again → previous `T_default` rejected; `T_W1` and `T_s2` still work.
22. **Revocation cascade-back (store-tier):** delete the `(stores, 2)` row → `T_s2` returns `401`; `T_W1` calls now include store-2 orders again; `T_default` is unchanged.
23. **Revocation cascade-back (website-tier):** delete the `(websites, 1)` row → `T_W1` returns `401`; `T_default` calls now include W1's stores (1 and 2) again.
24. **Disabled-store inclusion:** with `T_s2` issued, disable store 2 in admin → `curl -H "Authorization: MyParcel <T_s2>" /rest/V1/orders` still returns store-2 orders. Re-enabling store 2 has no observable effect on the permitted set (s2 was already covered).
25. **Native auth unaffected:** admin bearer token continues to access all granted resources — including those NOT in `ScopedResourceRegistry`.
26. **Unit suite:** `vendor/bin/pest` green. New unit tests cover:
    - `ApiAccessTokenUserContext::processRequest()` — header parsing, scheme casing, default-scope match, website-scope match, store-scope match, hash-miss → null, request without our header → null.
    - `ApiAccessTokenManager::generate()` / `clear()` — entropy, SHA-256 hash output matches `/^[0-9a-f]{64}$/`, hash ≠ plaintext, scope-aware persistence (default / websites / stores), scope-aware clearing, hash-uniqueness rejection (409), config cache flush invocation.
    - `TokenScopeContext::permittedStoreIds()` — three-tier ownership matrix: store-row beats website-row beats default-row; disabled-store inclusion; admin-store exclusion; null when no token authenticated this request; bulk row-set lookup is memoized for the request.
    - `OrderRepositoryStoreFilter::beforeGetList` — for each scope tier, applies the right `IN(...)` set; null-context no-ops.
    - `OrderRepositoryStoreFilter::afterGet` — out-of-scope `store_id` throws `NoSuchEntityException`; in-scope passes through.
    - `MyParcelTokenAclGate` — registry hit passes; registry miss throws `AuthorizationException` (→ 401); non-token contexts are bypassed.
    - `ScopedResourceRegistry` — registry-vs-`integration.xml` coverage regression test.

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Request` provides access to the `Authorization` header via `getHeader()`.
- The admin has permission to view/edit the MyParcel config section (existing ACL resource `MyParcelNL_Magento::myparcelnl_magento`).
- The supported scope levels are **default**, **websites**, and **stores** (Magento's leaf scope, matching `sales_order.store_id`). Other scope codes (custom group scopes, typos, future Magento additions) are rejected by the Generate controller with `400`.
- A merchant who runs a single-store install (one website, one store-view) treats this feature as effectively single-tenant: a default-scope token is sufficient, no website- or store-view tokens need to be issued, and partition behaviour is a no-op.
- The MyParcel backoffice presents the token alone for scope determination — it does NOT have to negotiate a `store_code` URL prefix or a `Store` request header to scope its calls.
- The `sales_order.store_id` foreign key (`vendor/magento/module-sales/etc/db_schema.xml`) is the authoritative partition key. Custom MyParcel endpoints that expose order-derived data MUST resolve `store_id` from the underlying `sales_order` row, not from request input.
- A **disabled store-view** remains in its owning token's permitted set: the owning token already had access; a disabled store does not generate new orders; pre-disablement orders remain queryable. Re-enabling a disabled store does not re-evaluate ownership — ownership is row-existence based and unaffected by `is_active`.
- The **admin store** (`store_id=0`) is never tokened and never returned in any token's permitted set. `StoreManagerInterface::getStores(false)` excludes admin from the candidate enumeration.
- A **website with zero store-views** is a valid (if unusual) website-scope token target; generation succeeds, and the resulting `permittedStoreIds()` is `[]`. The token authenticates but unlocks no data — a no-op until store-views are added under the website.

## Constraints

- Must not require any admin CLI operation or OAuth activation.
- Must not modify any `vendor/magento/**` file.
- The custom `MyParcel` Authorization scheme must coexist peacefully with Magento's native `Bearer` scheme — other modules and admin tokens continue to work unchanged. The allow-list (`MyParcelTokenAclGate`) MUST only activate for requests whose resolved `UserContextInterface` is `ApiAccessTokenUserContext`; native auth paths are never gated by it.
- Token expiry / TTL is explicitly out of scope for the first release; rotation is the operator's lever.
- **Supported scopes are `default`, `websites`, and `stores`.** Storage, admin visibility, and runtime read for any other scope (custom group scopes, typos, future Magento scope additions) are explicitly rejected: the Generate controller returns `400`; the admin form renders the *API Access* group at default, website, and store-view scopes only; `ApiAccessTokenUserContext` accepts hash matches only at `scope IN ('default','websites','stores')`.
- **Three-tier partition (not cascade) scoping is mandatory.** Per store, the most-specific row that *exists* (`stores > websites > default`) owns it; the presented token authenticates that store iff its resolved `(scopeType, scopeId)` equals the owner tuple. Implementation MUST NOT use `ScopeConfigInterface::getValue` for any token-related read (resolution OR membership), since that API performs cascading lookups against the config cache and would silently re-introduce hash-value-based matching; both reads go directly via `\Magento\Framework\App\ResourceConnection`.
- **Membership is row-coordinate, not hash-value.** `TokenScopeContext::permittedStoreIds()` MUST compute ownership by checking row *existence* at each tier in precedence order, then comparing row coordinates (`scope, scope_id`) to the resolved tuple. It MUST NOT compute ownership by re-hashing or by value-comparing cascaded reads — that would let two rows holding the same hash conflate.
- **Hash uniqueness invariant.** `ApiAccessTokenManager::generate()` MUST refuse to write a hash that already exists at any other `(scope, scope_id)` row on the same path; the Generate controller surfaces the conflict as `409 Conflict` with a clear admin-visible message and writes nothing. This guards against operator copy-paste of plaintext across scopes (which would conflate ownership in any value-based scheme and is otherwise a privilege-escalation footgun).
- **Allow-list deny-by-default.** Every ACL resource granted in `etc/integration.xml` MUST be accompanied by (a) a `ScopedResourceRegistry` entry and (b) a per-store filter plugin on the relevant repository or the relevant custom endpoint. Adding a grant without these is a regression — caught by a coverage test.
- **Filter plugin coverage.** A per-store filter plugin MUST cover BOTH `getList`-shaped (search criteria injection) and `get(id)`-shaped (out-of-scope → `NoSuchEntityException`) paths for the resource it scopes. Custom MyParcel endpoints inheriting `AbstractEndpoint` apply the same check via `permittedStoreIds()` before exposing record data.
- **URL prefix is decorative.** `/rest/{store_code}/V1/...` MUST NOT influence what a token-authenticated call returns. The token alone determines the permitted `store_id` set.

## Implementation notes

The implementation lives across the following files inside `app/code/MyParcelNL/Magento/`:

| File | Role |
|---|---|
| `src/Model/Authorization/ApiAccessTokenUserContext.php` | Custom `UserContextInterface` registered at `sortOrder=5`. Parses `Authorization: MyParcel <token>` (case-insensitive), hashes the presented token with SHA-256, looks up the hash via direct `core_config_data` SELECT (`path='myparcelnl_magento_general/api_access_token' AND value=<hash> AND scope IN ('default','websites','stores')`), compares with `hash_equals`, and on match returns `USER_TYPE_INTEGRATION` + the auto-provisioned integration's id. Side-effect: stores `(scopeType, scopeId)` on `TokenScopeContext` for downstream filter plugins. |
| `src/Model/Authorization/TokenScopeContext.php` | Request-scoped value object implementing `ResetAfterRequestInterface`. Holds `(scopeType, scopeId)` plus a memoized `permittedStoreIds(): ?int[]`. Returns `null` when no MyParcel token authenticated this request (so filter plugins no-op). On first call: one bulk SELECT — `SELECT scope, scope_id FROM core_config_data WHERE path='myparcelnl_magento_general/api_access_token'` — yielding `tokenedRows: Set<(scope, scope_id)>`. Then for each store from `StoreManagerInterface::getStores(false)` (admin excluded; disabled stores included), determines owner-row by precedence (`stores > websites > default`); store is permitted iff owner equals the resolved tuple. Result memoized for the request lifetime. Hash is never re-compared at the membership step — only row coordinates. |
| `src/Service/ApiAccessTokenManager.php` | Generates / clears tokens, scope-aware. `generate(string $scopeType, int $scopeId): string` accepts `default`, `websites`, or `stores`; returns plaintext exactly once and persists only the SHA-256 hash at `(scopeType, scopeId)`. Before persisting, `generate()` does a `SELECT scope, scope_id FROM core_config_data WHERE path=… AND value=<hash>` and throws a domain exception (rendered by the controller as `409 Conflict`) if the hash already exists at any other `(scope, scope_id)` — defends against operator copy-paste. `clear(string $scopeType, int $scopeId): void` deletes that scope's row and flushes the config cache type. `findHashScope(string $hash): ?array` returns `[scopeType, scopeId]` or null. No `getDecryptedToken()`. |
| `src/Service/ScopedResourceRegistry.php` | DI-configured allow-list of ACL resources covered by a per-store filter plugin. Initial entries: `Magento_Sales::actions_view`, `MyParcelNL_Magento::delivery_options_read`. Provides `isCovered(string $aclResource): bool`. Adding an entry without a backing filter plugin is a contract violation flagged by tests. |
| `Controller/Adminhtml/ApiAccessToken/Generate.php` | POST-only admin controller invoked by the *Generate* button. ACL = `MyParcelNL_Magento::myparcelnl_magento_api_access_token`. Accepts `scope=default` (with `scopeId=0`), `scope=websites` (with `scopeId>0` referring to a real website), or `scope=stores` (with `scopeId>0` referring to a real, non-admin store). Refuses with `400` for any other scope. On `ApiAccessTokenManager::generate()` raising a hash-conflict exception, returns `409 Conflict` with a clear admin-visible message and writes nothing. Otherwise returns `{ "token": "<plaintext>", "scope": "default"\|"websites"\|"stores", "scopeId": <int> }`. |
| `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` | Plugin on `Magento\Sales\Api\OrderRepositoryInterface`. `beforeGetList(SearchCriteria $sc)` injects `store_id IN (permittedStoreIds)` from `TokenScopeContext::permittedStoreIds()`; no-op when null. The same `IN(...)` clause covers all three scope tiers — there is no separate `NOT IN` branch any more, since the three-tier algorithm produces the permitted set directly. `afterGet(OrderInterface $order)` throws `NoSuchEntityException` when the loaded order's `store_id` is outside the permitted set; passes through otherwise or when context is null. |
| `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` | Plugin on `Magento\Webapi\Controller\Rest\RequestValidator::validate`. After the native `validate` accepts the request, if the resolved `UserContextInterface` is `ApiAccessTokenUserContext` AND the route's required ACL resource is NOT in `ScopedResourceRegistry`, throws `AuthorizationException` (→ `401`). Native bearer/admin/customer auth is unaffected. |
| `src/Model/Rest/AbstractEndpoint.php` (modify) | Adds `permittedStoreIds(): ?int[]` accessor sourced from the injected `TokenScopeContext`, and a helper `assertStoreInScope(int $storeId): void` that throws `ProblemDetails` 404 when out of scope. Concrete endpoints exposing order-derived data (`OrderDeliveryOptionsV1Resource`) call it before dereferencing the order. |
| `src/Model/Rest/Resource/OrderDeliveryOptionsV1Resource.php` (modify) | Calls `assertStoreInScope($order->getStoreId())` before serializing. |
| `src/Block/System/Config/Form/ApiAccessTokenField.php` + `view/adminhtml/templates/api_access_token_field.phtml` | Frontend model rendering masked input + Generate button + small AJAX snippet. Reads the current scope from the admin context so it manages the right token row; renders at default, website, and store-view scopes. The block disambiguates `(scopeType, scopeId)` for the AJAX call and surfaces the controller's `409 Conflict` response with a clear admin-visible error when a hash already exists at another scope. |
| `etc/integration.xml` | Auto-provisions the inactive "MyParcel API" integration on `setup:upgrade` and grants its ACL resources (`Magento_Sales::actions_view`, `MyParcelNL_Magento::delivery_options_read`). |
| `etc/webapi_rest/di.xml` | Registers `ApiAccessTokenUserContext` in `CompositeUserContext` at `sortOrder=5`; registers `OrderRepositoryStoreFilter` plugin; registers `MyParcelTokenAclGate` plugin on `RequestValidator`; declares `ScopedResourceRegistry` as a `virtualType` with the initial allow-list. |
| `etc/adminhtml/system.xml` (modify) | Adds the `api_access` group under `myparcelnl_magento_dynamic_settings` with `showInDefault=1, showInWebsite=1, showInStore=1`. The admin-visible `<comment>` warns the token is shown once, that the integration is auto-managed, and that issuing a token at a less-specific scope does **not** override a more-specific row that already exists (e.g. issuing at default does not "take back" stores covered by a website or store row). Per-scope copy: default — "Covers every store **not** tokened separately at website or store-view scope"; website — "Covers every store-view in this website **not** tokened separately at store-view scope; removes those stores from the default-scope token's view"; store-view — "Covers only this store; removes it from the default-scope and parent-website token's view." |
| `etc/acl.xml` | Adds the `MyParcelNL_Magento::myparcelnl_magento_api_access_token` resource guarding the Generate controller. |
| `etc/module.xml` (modify) | Adds `Magento_Webapi`, `Magento_Integration`, `Magento_Authorization`, `Magento_Backend`, `Magento_Config`, `Magento_Store` to the `<sequence>`. |
| `composer.json` (modify) | Adds explicit `require` entries for `magento/module-integration`, `magento/module-authorization`, `magento/module-webapi`, `magento/module-config`, `magento/module-store`. |
| `Tests/Unit/Model/Authorization/ApiAccessTokenUserContextTest.php` | Header parsing, scheme casing, default-scope match, website-scope match, store-scope match, hash-miss → null, no-header → null. |
| `Tests/Unit/Model/Authorization/TokenScopeContextTest.php` | Three-tier ownership matrix: `(stores, S)` row beats `(websites, W(S))` row beats `(default, 0)` row; disabled-store inclusion in the owner's permitted set; admin store (`id=0`) excluded; null when no token authenticated this request; bulk row-set lookup is memoized for the request. |
| `Tests/Unit/Service/ApiAccessTokenManagerTest.php` | Entropy, hash output, hash ≠ plaintext, scope-aware `generate`/`clear` for default / websites / stores, `findHashScope` returns the right scope, hash-uniqueness conflict raises (controller maps to 409), config cache flush invocation. |
| `Tests/Unit/Plugin/Magento/Sales/OrderRepositoryStoreFilterTest.php` | For each scope tier (default / websites / stores), `beforeGetList` applies `IN(permittedSet)` consistent with `TokenScopeContext::permittedStoreIds()`; null context is a no-op for both `beforeGetList` and `afterGet`; `afterGet` throws `NoSuchEntityException` for out-of-scope orders. |
| `Tests/Unit/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGateTest.php` | Token + registry hit → pass; token + registry miss → 401; non-token user context → bypass; missing ACL resource on route → conservative bypass (let native validator handle). |
| `Tests/Unit/Service/ScopedResourceRegistryCoverageTest.php` | Parses `etc/integration.xml` and asserts every granted ACL resource is in `ScopedResourceRegistry` (regression test against silent leaks). |

Implementation tickets derive from US-000001..5 and trace back to this TR's §Specifications criteria tables. The §Related ADRs section above lists what each PHPDoc / XML class-header comment must cover when those files are written.
