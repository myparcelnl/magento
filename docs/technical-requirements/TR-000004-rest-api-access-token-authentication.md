# TR-000004: REST API Access Token Authentication

> **Status:** Draft — targeting branch `feat/rest-api-access-token-auth`. The scope model is three-tier (`default` / `websites` / `stores`) with row-coordinate partition ownership; §Rationale is authoritative. Revision history is in git.

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

**No standalone ADRs.** This module does not maintain a `docs/architectural-decisions/` directory. All design rationale lives in §Rationale and §Constraints of this TR. Class-level docblocks on the implementation files carry only what a reader cannot get from the code itself; for the "why" they refer here.

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
- Per-store ownership is computed in PHP from that set plus `StoreManagerInterface::getStores()` (yielding the `store→website` map; admin store id=0 is excluded by passing `withDefault=false`). The resulting permitted set per tier is specified in §Security Criteria.

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
| Token entropy | 256 bits of OS-sourced randomness (`random_bytes(32)`) | Code inspection of `TokenService::generateForScope()` |
| Token presentation | Hex-encoded (64 lowercase hex chars) | Unit test: `generate()` output matches `/^[0-9a-f]{64}$/` |
| Storage hashing | Persisted as a SHA-256 hash (`hash('sha256', $token)` → 64 lowercase hex chars). Plaintext is never stored, never logged, never recoverable from storage. | Scenarios 2–4. |
| Comparison | Constant-time via `hash_equals` | Code inspection of `ApiAccessTokenUserContext::processRequest()` |
| Plaintext exposure | Returned to admin exactly once, immediately after generation; never logged, never re-readable from storage | Unit test: `generate()` returns plaintext but config stores only the SHA-256 hash; admin UI on reload shows masked placeholder only; no server-side code path reads plaintext from storage. |
| Scheme | `Authorization: MyParcel <token>` (case-insensitive scheme) | Unit test: `Bearer`, empty, malformed, and other schemes all bail (`userType=null`). |
| ACL enforcement (install-wide) | Requests with valid token but resource not in integration grants return `401` | Scenario 20. |
| Allow-list enforcement (deny-by-default) | Requests with valid token against a granted ACL resource that is NOT in `ScopedResourceRegistry` return `401` (token-only); the same resource called with an admin bearer token continues to return `200` | Scenario 19. |
| Rotation isolation | Generating a new token at scope `S` invalidates the previous token at scope `S` immediately and ONLY at scope `S`. Tokens at other scopes are unaffected. | Scenario 21. |
| Revocation isolation + cascade-back | Clearing the row at scope `S` causes subsequent requests with that token to return `401`; if `S` was a store-view scope, that store's data rejoins the default token's view on the next request. | Scenarios 22–23. |
| Scope partitioning (default-scope token) | When the presented token is bound to default scope, returned `store_id`s are every enabled non-admin store **minus** stores in any website with a website-scope row at `(path='myparcelnl_magento_general/api_access_token', scope='websites', scope_id=…)` **minus** stores with their own row at `(scope='stores', scope_id=…)`. | Scenarios 10–12, 22–23. |
| Scope partitioning (website-scoped token) | When the presented token is bound to website `W`, returned `store_id`s are every enabled non-admin store in `W` **minus** stores in `W` with their own dedicated `(stores, S)` row. | Scenarios 13–14, 22. |
| Scope partitioning (store-view-scoped token) | When the presented token is bound to store-view `S`, returned records ALL satisfy `store_id = S`, regardless of URL prefix. | Scenarios 15–16. |
| Disabled-store inclusion | A store that is disabled at the moment of the request remains in its owning token's permitted set; its already-existing orders are queryable. New orders cannot be created for a disabled store, so this exposes no future data the owning token did not already cover. Re-enabling a disabled store does not change ownership. | Scenario 24. |
| Existence-leak prevention | Single-record retrieval of an out-of-scope record returns `404`, not `403`, so callers cannot probe existence by status code. | Scenarios 17–18. |
| Storage scope acceptance | Generate / clear controller accepts `scope=default`, `scope=websites`, and `scope=stores`; rejects any other scope value (custom scope codes, typos) with `400`. | Scenario 5. |
| Hash uniqueness invariant | At write time, generating a token whose SHA-256 hash already exists at any other `(scope, scope_id)` row on the same path returns `409 Conflict` with a clear admin-visible message; no row is written. Defends against operator copy-paste of plaintext across scopes. | Scenario 6. |

### Compatibility Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| UserContext registration | Custom context registered at `sortOrder=5` in `CompositeUserContext` chain, ahead of native `tokenUserContext` (`sortOrder=10`) | `bin/magento object-manager:debug Magento\Authorization\Model\CompositeUserContext` or equivalent inspection — `myParcelTokenUserContext` appears first in array. |
| Bypass safety when token absent | When no `Authorization: MyParcel` header is present, context returns `(userType=null, userId=null)`, `TokenScopeContext::permittedStoreIds()` is `null`, and downstream filter plugins no-op. The composite chain falls through to native contexts unchanged. | Unit test: request without our header → our context returns nulls; filter plugin's `beforeGetList` does NOT touch the SearchCriteria; native contexts process normally. |
| Native auth paths unaffected | Admin bearer tokens, customer tokens, and other modules' integrations continue to work unchanged on filtered AND non-filtered resources. The `MyParcelTokenAclGate` plugin only activates when our `UserContext` is the resolved one. | Scenarios 19, 25. |
| URL prefix decorative for token auth | `/rest/{store_code}/V1/...` is honoured by `PathProcessor` (sets the StoreManager's current store) but does NOT widen or narrow what a token-authenticated call can read. | Scenario 16. |
| `/rest/all/V1/...` GET-blocked by Magento | Native Magento blocks `GET /rest/all/V1/...` (`vendor/magento/module-webapi/Controller/Rest.php:270-275`). Our scheme inherits this behaviour — no special handling required. | Integration test: `T_default` against `/rest/all/V1/orders` → Magento returns its native error, not unfiltered data. |
| Magento version | Works on Magento 2.4.4+ regardless of `oauth/consumer/enable_integration_as_bearer` value | Integration test: with flag both `0` (default) and `1`, our scheme works identically. |
| ACL grant mechanism | Integration role + rules created by Magento's own `Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations → grantPermissions` on `setup:upgrade` | Scenario 1. |
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
    - `TokenService::generateForScope()` / `revokeForScope()` — entropy, SHA-256 hash output matches `/^[0-9a-f]{64}$/`, hash ≠ plaintext, scope-aware persistence (default / websites / stores), scope-aware clearing, hash-uniqueness rejection (409), config cache flush invocation.
    - `TokenScopeContext::permittedStoreIds()` — three-tier ownership matrix: store-row beats website-row beats default-row; disabled-store inclusion; admin-store exclusion; null when no token authenticated this request; bulk row-set lookup is memoized for the request.
    - `OrderRepositoryStoreFilter::beforeGetList` — for each scope tier, applies the right `IN(...)` set; null-context no-ops.
    - `OrderRepositoryStoreFilter::afterGet` — out-of-scope `store_id` throws `NoSuchEntityException`; in-scope passes through.
    - `MyParcelTokenAclGate` — registry hit passes; registry miss throws `AuthorizationException` (→ 401); non-token contexts are bypassed.
    - `ScopedResourceRegistry` — registry-vs-`integration.xml` coverage regression test.

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Request` provides access to the `Authorization` header via `getHeader()`.
- The admin has permission to view/edit the MyParcel config section (existing ACL resource `MyParcelNL_Magento::myparcelnl_magento`).
- A merchant who runs a single-store install (one website, one store-view) treats this feature as effectively single-tenant: a default-scope token is sufficient, no website- or store-view tokens need to be issued, and partition behaviour is a no-op.
- The MyParcel backoffice presents the token alone for scope determination — it does NOT have to negotiate a `store_code` URL prefix or a `Store` request header to scope its calls.
- The `sales_order.store_id` foreign key (`vendor/magento/module-sales/etc/db_schema.xml`) is the authoritative partition key. Custom MyParcel endpoints that expose order-derived data MUST resolve `store_id` from the underlying `sales_order` row, not from request input.
- A **website with zero store-views** is a valid (if unusual) website-scope token target; generation succeeds, and the resulting `permittedStoreIds()` is `[]`. The token authenticates but unlocks no data — a no-op until store-views are added under the website.

## Constraints

- Must not require any admin CLI operation or OAuth activation.
- Must not modify any `vendor/magento/**` file.
- The custom `MyParcel` Authorization scheme must coexist peacefully with Magento's native `Bearer` scheme — other modules and admin tokens continue to work unchanged. The allow-list (`MyParcelTokenAclGate`) MUST only activate for requests whose resolved `UserContextInterface` is `ApiAccessTokenUserContext`; native auth paths are never gated by it.
- Token expiry / TTL is explicitly out of scope for the first release; rotation is the operator's lever.
- **Supported scopes are `default`, `websites`, and `stores`.** Storage, admin visibility, and runtime read for any other scope (custom group scopes, typos, future Magento scope additions) are explicitly rejected: the Generate controller returns `400`; the admin form renders the *API Access* group at default, website, and store-view scopes only; `ApiAccessTokenUserContext` accepts hash matches only at `scope IN ('default','websites','stores')`.
- **Three-tier partition (not cascade) scoping is mandatory** — §Rationale states the ownership rule. Implementation MUST NOT use `ScopeConfigInterface::getValue` for any token-related read (resolution OR membership), since that API performs cascading lookups against the config cache and would silently re-introduce hash-value-based matching; both reads go directly via `\Magento\Framework\App\ResourceConnection`.
- **Membership is row-coordinate, not hash-value.** `TokenScopeContext::permittedStoreIds()` MUST compute ownership by checking row *existence* at each tier in precedence order, then comparing row coordinates (`scope, scope_id`) to the resolved tuple. It MUST NOT compute ownership by re-hashing or by value-comparing cascaded reads — that would let two rows holding the same hash conflate.
- **Hash uniqueness invariant.** `TokenService::generateForScope()` MUST refuse to write a hash that already exists at any other `(scope, scope_id)` row on the same path; the Generate controller surfaces the conflict as `409 Conflict` with a clear admin-visible message and writes nothing. This guards against operator copy-paste of plaintext across scopes (which would conflate ownership in any value-based scheme and is otherwise a privilege-escalation footgun).
- **Allow-list deny-by-default.** Every ACL resource granted in `etc/integration.xml` MUST be accompanied by (a) a `ScopedResourceRegistry` entry and (b) a per-store filter plugin on the relevant repository or the relevant custom endpoint. Adding a grant without these is a regression — caught by a coverage test.
- **Filter coverage is per service, not per resource.** The allow-list operates on ACL resources while filtering operates on services, so one registry entry can open more routes than were reasoned about. Before allow-listing a resource, every route Magento maps to it MUST be enumerated and each backing service either store-filtered or consciously recorded as global. `Magento_Sales::actions_view` is the worked example: six routes across three services, originally filtered on `OrderRepositoryInterface` only — a cross-scope order-data leak (security review 2026-07, Finding 1). The route→filter mapping lives in `docs/design/adding-a-token-accessible-rest-endpoint.md` (Access matrix) and MUST be updated whenever a grant changes.
- **Filter plugin coverage.** A per-store filter plugin MUST cover BOTH `getList`-shaped (search criteria injection) and `get(id)`-shaped (out-of-scope → `NoSuchEntityException`) paths for the resource it scopes. Custom MyParcel endpoints inheriting `AbstractEndpoint` apply the same check via `permittedStoreIds()` before exposing record data.
- **Identity and scope reset together.** `ApiAccessTokenUserContext` and `TokenScopeContext` hold two halves of the same per-request state, so both MUST implement `ResetAfterRequestInterface`. If only one resets, a long-running runtime carries a memoized identity into the next request while the scope owner is already `null` — authenticated as the integration user with `permittedStoreIds() === null`, silently disabling every filter above (security review 2026-07, Finding 3).
- **URL prefix is decorative.** `/rest/{store_code}/V1/...` MUST NOT influence what a token-authenticated call returns. The token alone determines the permitted `store_id` set.

## Implementation notes

The implementation lives across the following files inside `app/code/MyParcelNL/Magento/`:

| File | Role |
|---|---|
| `src/Model/Authorization/ApiAccessTokenUserContext.php` | Custom `UserContextInterface` at `sortOrder=5`. Parses the `MyParcel` scheme, matches the presented token's SHA-256 hash with `hash_equals`, and on a hit returns `USER_TYPE_INTEGRATION` plus the auto-provisioned integration's id. Side effect: pins `(scopeType, scopeId)` on `TokenScopeContext` for the filter plugins. |
| `src/Model/Authorization/TokenScopeContext.php` | Request-scoped value object (`ResetAfterRequestInterface`) holding `(scopeType, scopeId)` and a memoized `permittedStoreIds(): ?int[]` — `null` when no MyParcel token authenticated the request, so the filter plugins no-op. Implements the ownership algorithm from §Rationale. |
| `src/Service/ApiAccessToken/TokenService.php` | Scope-aware token lifecycle. `generateForScope(string $scope, int $scopeId): string` returns the plaintext once and persists only its SHA-256 hash, after enforcing the hash-uniqueness invariant from §Constraints (`AlreadyExistsException`). `revokeForScope()` deletes the row and is idempotent. Both normalise the coordinate — default is forced to `scopeId=0`, other scopes require `scopeId > 0`, anything else raises `InputException` — and flush the `config` cache type. Exposes `hashToken()`; there is no read-back of plaintext. |
| `src/Service/ApiAccessToken/RandomBytesGenerator.php` + `src/Service/ApiAccessToken/RandomBytesGeneratorInterface.php` | Wraps `random_bytes()` behind an interface so a test can force a chosen token, and therefore a chosen hash, to exercise the uniqueness invariant (scenario 6). |
| `src/Service/ScopedResourceRegistry.php` | DI-configured allow-list of ACL resources that have a per-store filter plugin. An entry without a backing plugin is a contract violation the tests flag. |
| `Controller/Adminhtml/ApiAccessToken/AbstractTokenAction.php` | Shared base for the two token controllers. Declares `ADMIN_RESOURCE = MyParcelNL_Magento::myparcelnl_magento_api_access_token`, reads the `(scope, scopeId)` pair off the request, and renders errors as JSON with an explicit HTTP status. |
| `Controller/Adminhtml/ApiAccessToken/Generate.php` | POST-only. Returns `{ "success": true, "token": "<plaintext>" }`. Maps `AlreadyExistsException` to `409` and `InputException` to `400`, each carrying the service's message. Absent params default to `(default, 0)`. |
| `Controller/Adminhtml/ApiAccessToken/Revoke.php` | POST-only. Returns `{ "success": true }`; maps `InputException` to `400`. |
| `src/Service/Authorization/StoreScopeSearchCriteria.php` | Shared `store_id IN (...)` injector for `getList` paths. The constraint gets its own `FilterGroup` — filters within a group are OR-ed, groups AND-ed — so it holds whatever the caller filtered on. `IN (-1)` when the permitted set is empty. |
| `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` | Plugin on `OrderRepositoryInterface`. `beforeGetList` delegates to `StoreScopeSearchCriteria`; `afterGet` throws `NoSuchEntityException` out of scope. One `IN(...)` covers all three tiers, so there is no `NOT IN` branch. |
| `src/Plugin/Magento/Sales/OrderItemRepositoryStoreFilter.php` | Plugin on `OrderItemRepositoryInterface` for `/V1/orders/items*`. Scope comes from the item's own `store_id`, which is nullable — `NULL` casts to `0`, never a valid store id, so unattributable rows stay invisible. |
| `src/Plugin/Magento/Sales/OrderManagementStoreFilter.php` | Plugin on `OrderManagementInterface` for `/V1/orders/:id/comments` and `/statuses`. Both take only an order id, so the check is delegated to the already-filtered `OrderRepositoryInterface`. |
| `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` | Plugin on `RequestValidator::validate`. After native validation, throws `AuthorizationException` (→ `401`) when the caller is `ApiAccessTokenUserContext` and the route's ACL resource is not in `ScopedResourceRegistry`. |
| `src/Model/Rest/AbstractEndpoint.php` (modify) | Adds `permittedStoreIds(): ?int[]` and `assertStoreInScope(int $storeId): void`, which throws a `ProblemDetails` 404 out of scope. |
| `src/Model/Rest/Resource/OrderDeliveryOptionsV1Resource.php` (modify) | Calls `assertStoreInScope($order->getStoreId())` before serializing. |
| `src/Block/System/Config/Form/ApiAccessTokenButton.php` + `view/adminhtml/templates/api_access_token_button.phtml` | Masked field, Generate and Revoke buttons, and the AJAX calls to both controllers. Reads the current admin scope so it acts on the right token row, reports whether a token already exists there, and supplies the per-scope warning copy. |
| `etc/integration.xml` | Auto-provisions the inactive "MyParcel API" integration on `setup:upgrade` and grants its ACL resources (`Magento_Sales::actions_view`, `MyParcelNL_Magento::delivery_options_read`). |
| `etc/webapi_rest/di.xml` | Registers `ApiAccessTokenUserContext` in `CompositeUserContext` at `sortOrder=5`; registers the three store-filter plugins (`OrderRepositoryStoreFilter`, `OrderItemRepositoryStoreFilter`, `OrderManagementStoreFilter`); registers `MyParcelTokenAclGate` plugin on `RequestValidator`; declares `ScopedResourceRegistry` as a `virtualType` with the initial allow-list. |
| `etc/adminhtml/system.xml` (modify) | Adds the `api_access` group under `myparcelnl_magento_dynamic_settings`, shown at all three scopes. Per-scope admin `<comment>` copy: default — "Covers every store **not** tokened separately at website or store-view scope"; website — "Covers every store-view in this website **not** tokened separately at store-view scope; removes those stores from the default-scope token\'s view"; store-view — "Covers only this store; removes it from the default-scope and parent-website token\'s view." |
| `etc/acl.xml` | Adds the `MyParcelNL_Magento::myparcelnl_magento_api_access_token` resource guarding the Generate controller. |
| `etc/module.xml` (modify) | Adds `Magento_Webapi`, `Magento_Integration`, `Magento_Authorization`, `Magento_Backend`, `Magento_Config`, `Magento_Store` to the `<sequence>`. |
| `composer.json` (modify) | Adds explicit `require` entries for `magento/module-integration`, `magento/module-authorization`, `magento/module-webapi`, `magento/module-config`, `magento/module-store`. |
| `Tests/Unit/Model/Authorization/ApiAccessTokenUserContextTest.php` | Header parsing, scheme casing, default-scope match, website-scope match, store-scope match, hash-miss → null, no-header → null. |
| `Tests/Unit/Model/Authorization/TokenScopeContextTest.php` | Three-tier ownership matrix: `(stores, S)` row beats `(websites, W(S))` row beats `(default, 0)` row; disabled-store inclusion in the owner's permitted set; admin store (`id=0`) excluded; null when no token authenticated this request; bulk row-set lookup is memoized for the request. |
| `Tests/Unit/Service/ApiAccessToken/TokenServiceTest.php` | Hex shape of the plaintext; the persisted hash equals SHA-256 of it; default scope forced to `scopeId=0`; `InputException` for an unsupported scope and for a non-positive `scopeId`; `AlreadyExistsException` for a hash held at another coordinate, with no write and no cache flush; self-overwrite at the same coordinate allowed; cache flushed exactly once on success. |
| `Tests/Unit/Service/ApiAccessToken/ApiAccessTokenRotationTest.php` | Rotation overwrites the row in place; the previous token stops authenticating and the new one takes over at the same scope; rotating one tier leaves the other tiers' partitions untouched; `Bearer` requests stay unintercepted. |
| `Tests/Unit/Service/ApiAccessToken/ApiAccessTokenRevocationTest.php` | Revocation rejects the previous token; cascade-back returns a store to its parent website token and a website's untokened stores to default; revoke is idempotent and forces `scopeId=0` at default scope; regenerating after a revoke issues a fresh token. |
| `Tests/Unit/Controller/Adminhtml/ApiAccessToken/GenerateTest.php` | Success payload; `AlreadyExistsException` → `409`; `InputException` → `400`; absent params default to `(default, 0)`. |
| `Tests/Unit/Controller/Adminhtml/ApiAccessToken/RevokeTest.php` | Success payload; `InputException` → `400`. |
| `Tests/Unit/Block/System/Config/Form/ApiAccessTokenButtonTest.php` | The per-scope warning copy: default; website, warning about removal from the default token's view; store-view, warning about removal from the default and parent-website views. |
| `Tests/Unit/Plugin/Magento/Sales/OrderRepositoryStoreFilterTest.php` | `beforeGetList` delegates to `StoreScopeSearchCriteria`; null context is a no-op for both `beforeGetList` and `afterGet`; `afterGet` throws `NoSuchEntityException` for out-of-scope orders. |
| `Tests/Unit/Service/Authorization/StoreScopeSearchCriteriaTest.php` | Non-token request leaves the criteria untouched; a permitted set appends a `store_id IN (...)` group preserving existing groups; an empty set substitutes `IN (-1)`; a null return from `getFilterGroups()` is tolerated. |
| `Tests/Unit/Plugin/Magento/Sales/OrderItemRepositoryStoreFilterTest.php` | `beforeGetList` delegates to `StoreScopeSearchCriteria`; `afterGet` passes in-scope items, throws `NoSuchEntityException` for out-of-scope items, and fails closed on a null `store_id`. |
| `Tests/Unit/Plugin/Magento/Sales/OrderManagementStoreFilterTest.php` | Driven over both intercepted methods: no repository load without a token; the order id is resolved through the store-filtered repository as an int; the repository's 404 propagates; an empty permitted set still consults the repository. |
| `Tests/Unit/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGateTest.php` | Token + registry hit → pass; token + registry miss → 401; non-token user context → bypass; missing ACL resource on route → conservative bypass (let native validator handle). |
| `Tests/Unit/Service/ScopedResourceRegistryTest.php` | Nothing covered when unconfigured; covers exactly the configured resource ids; ids are case-sensitive; `etc/integration.xml` grants are a subset of the registry in `etc/webapi_rest/di.xml` (regression test against silent leaks). |

Implementation tickets derive from US-000001..6 and trace back to this TR's §Specifications criteria tables.
