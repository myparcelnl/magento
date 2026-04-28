# TR-000004: REST API Token Authentication

> **Status:** Draft — targeting branch `feat/rest-api-token-auth`. Revised 2026-04-28: auth scheme renamed `MyParcelNL` → `MyParcel`; API Access admin caveat copy added; storage primitive switched from `EncryptorInterface::encrypt` to one-way SHA-256 hashing; reorganized into BR-000002 / FR-000005 / US-000001..4 with cross-cutting design rationale retained in §Rationale and §Constraints below.
>
> **Revised 2026-04-28 (later same day):** the default-scope-only single-tenant constraint is **reversed** — tokens are now multi-tenant at default + store-view scopes with partition semantics (a store-view-scoped token shadows that store-view from the default token's view). A new *scoped-resource allow-list* enforces that token-authenticated calls only reach REST resources for which the module ships a per-store filter plugin. New supporting components: `TokenScopeContext`, `ScopedResourceRegistry`, `OrderRepositoryStoreFilter`, `MyParcelTokenAclGate`, plus a `permittedStoreIds()` accessor on `src/Model/Rest/AbstractEndpoint.php`. New US-000005 covers store-view-scoped generation.

## Related Business Requirements

- [BR-000002 — MyParcel backoffice authenticates against customer Magento REST API](../business-requirements/BR-000002-myparcel-backoffice-rest-auth.md)

## Related Functional Requirements

- [FR-000005 — Self-service API token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-token.md)

## Related User Stories

- [US-000001 — Admin generates API token](../user-stories/US-000001-admin-generates-api-token.md)
- [US-000002 — Admin rotates API token](../user-stories/US-000002-admin-rotates-api-token.md)
- [US-000003 — Admin revokes API token](../user-stories/US-000003-admin-revokes-api-token.md)
- [US-000004 — REST caller authenticates with token](../user-stories/US-000004-rest-caller-authenticates-with-token.md)
- [US-000005 — Admin generates store-scoped API token](../user-stories/US-000005-admin-generates-store-scoped-api-token.md)

## Related ADRs

**No standalone ADRs.** This module does not maintain a `docs/architectural-decisions/` directory. Design rationale is split between:

- **Cross-cutting decisions** — captured in §Rationale (custom `UserContextInterface` bypassing Magento's bearer-token gate; partition vs. cascade scoping; allow-list deny-by-default) and §Constraints (scope-level support; per-store filter coverage) of this TR.
- **Local class-level decisions** — documented as PHPDoc / XML comments on the implementation files, written when those files are created. Required content (the contract):
    - `src/Model/Authorization/ApiTokenUserContext.php` — why the class exists at all, why a custom `MyParcel` scheme, why `sortOrder=5`, why `USER_TYPE_INTEGRATION`, plus a reference table of `vendor/magento/**` files this class interacts with. Also: how the resolved token's scope is stored on `TokenScopeContext` for downstream filter plugins to read.
    - `src/Model/Authorization/TokenScopeContext.php` — why a separate value object (request-scoped, `ResetAfterRequestInterface`); why memoize the carved-out store_id list; why `permittedStoreIds()` is `null` for non-token requests (so the filter plugin no-ops).
    - `src/Service/ApiTokenManager.php` — why SHA-256 hashing instead of `EncryptorInterface::encrypt` (verify-only credential; encryption gives no defence against DB+filesystem read; decouples from `crypt/key`); why fast hash and not bcrypt/argon2 (256-bit random input → no dictionary attack surface); why `core_config_data` rows at multiple scopes (default + stores); why no `getDecryptedToken()` method; why config cache flush is part of the generate/clear flow.
    - `src/Service/ScopedResourceRegistry.php` — why a deny-by-default allow-list (closes the leak risk of new ACL grants); why the registry is DI-configured (so future endpoints add themselves declaratively); the contract that a registry entry MUST be backed by a filter plugin.
    - `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` — why both `beforeGetList` and `afterGet` are needed; why `NoSuchEntityException` (404) and not `AuthorizationException` (403) for out-of-scope `get(id)`; why the plugin no-ops when `TokenScopeContext` is unset.
    - `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` — why this gate runs only for token-authenticated requests, why it sits as a plugin on `RequestValidator::validate`, why it returns 401 (not 403) when the resolved resource is outside the registry.
    - `etc/integration.xml` — why the integration is auto-provisioned and never activated; the three-step `grantPermissions` mechanism; how integration grants relate to the scoped-resource allow-list (grants are install-wide; per-store enforcement is layered on top).

## Category

Security (primary), Compatibility (secondary — Magento 2.4.4+ integration auth gate).

## Requirement

Customers of the Magento module MUST be able to authenticate a remote MyParcel backoffice caller to their Magento REST API by:

1. Running `bin/magento setup:upgrade` once (standard module upgrade step).
2. Clicking a single **Generate API token** button in the MyParcel admin config.
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

### Partition vs cascade scoping

Magento's native scope system is *cascading*: a value at store-view scope falls back to website, then to default. The token model is *partitioned*: a store-view-scoped token does NOT fall back to default; conversely, the default-scope token's view is NOT the union of all stores — it is the **complement** of the set of stores with a dedicated token.

This partition is required by the MyParcel side of the integration: if a merchant issues a separate API token for store-view S, that store's data must not be visible to *any* token other than S's. Cascading would either (a) leak S into the default token's view, or (b) hide S from default but also hide it from S's own token, depending on direction.

Implementation consequences:

- The default-scope token resolves at request time to "all stores **minus** the set of `scope_id`s with a `myparcelnl_magento_general/api_token` row at `scope='stores'`."
- A store-view-scoped token resolves to a single-element set `{scope_id}`.
- The set is captured in a request-scoped `TokenScopeContext` and consulted by every per-store filter plugin.

### Allow-list (deny-by-default for non-filtered resources)

The earlier draft of this TR assumed any newly granted ACL resource in `etc/integration.xml` would "just work." Under per-store filtering, that property would silently leak data: a token at store-view S would receive *all* customers / all CMS pages / etc. unless a per-store filter plugin existed for each grant.

We close this by introducing a *scoped-resource allow-list* (`ScopedResourceRegistry`) and a `MyParcelTokenAclGate` plugin on `RequestValidator::validate`. Token-authenticated requests against any granted resource that is not in the registry return `401`. Adding a new ACL grant therefore requires three coordinated changes (grant in `etc/integration.xml`, filter plugin on the relevant repository, registry entry) — verified by a regression test that asserts every grant has a matching registry entry.

This is an explicit trade-off versus TR-000004's earlier "grant + go" forward-compat property: future ACL additions cost more, but cannot leak.

### Storage shape (multi-scope `core_config_data`)

Multi-scope `core_config_data` rows on path `myparcelnl_magento_general/api_token` (rather than a dedicated table) keep the storage primitive aligned with the rest of the module's config and the prior TR. Trade-offs:

- **Pro:** zero schema migration; reuses Magento's scope semantics (`scope` + `scope_id`); the admin UI's per-scope rendering follows Magento's standard config form.
- **Pro:** clear/generate writes integrate with Magento's config write API (which already invalidates the config cache type).
- **Con:** the partition query ("which `scope_id`s have a row?") goes via `\Magento\Framework\App\ResourceConnection` directly (not `ScopeConfigInterface`), since we don't know the scope of the presented token until after we've matched its hash.
- **Con:** mixes "configuration" and "credential storage" semantically. Mitigated by keeping the path under a dedicated `general/api_token` namespace and never reading the value through `ScopeConfigInterface`.

Baseline verified: `vendor/magento/module-webapi/Controller/Rest/RequestValidator.php:104` `checkPermissions` had a temporary `return;` that has been reverted; native ACL enforcement is working normally.

## Specifications

### Security Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Token entropy | 256 bits of OS-sourced randomness (`random_bytes(32)`) | Code inspection of `ApiTokenManager::generate()` |
| Token presentation | Hex-encoded (64 lowercase hex chars) | Unit test: `generate()` output matches `/^[0-9a-f]{64}$/` |
| Storage hashing | Persisted as a SHA-256 hash (`hash('sha256', $token)` → 64 lowercase hex chars). Plaintext is never stored, never logged, never recoverable from storage. | Inspection of `core_config_data.value` for any row at path `myparcelnl_magento_general/api_token` — value is a 64-char hex SHA-256 digest of the issued token, NOT the token itself; reversing the digest is computationally infeasible. |
| Comparison | Constant-time via `hash_equals` | Code inspection of `ApiTokenUserContext::processRequest()` |
| Plaintext exposure | Returned to admin exactly once, immediately after generation; never logged, never re-readable from storage | Unit test: `generate()` returns plaintext but config stores only the SHA-256 hash; admin UI on reload shows masked placeholder only; no server-side code path reads plaintext from storage. |
| Scheme | `Authorization: MyParcel <token>` (case-insensitive scheme) | Unit test: `Bearer`, empty, malformed, and other schemes all bail (`userType=null`). |
| ACL enforcement (install-wide) | Requests with valid token but resource not in integration grants return `401` | Integration test: token + `GET /V1/customers/1` (no `Magento_Customer::manage` grant) → `401`. |
| Allow-list enforcement (deny-by-default) | Requests with valid token against a granted ACL resource that is NOT in `ScopedResourceRegistry` return `401` (token-only); the same resource called with an admin bearer token continues to return `200` | Integration test: grant a "harness" ACL resource on the integration, do not register it in `ScopedResourceRegistry` → token call returns `401`; admin call returns `200`. |
| Rotation isolation | Generating a new token at scope `S` invalidates the previous token at scope `S` immediately and ONLY at scope `S`. Tokens at other scopes are unaffected. | Integration test: generate `T_default` and `T_s1` → re-generate `T_default` → previous default token returns `401`, `T_s1` still returns `200`. |
| Revocation isolation + cascade-back | Clearing the row at scope `S` causes subsequent requests with that token to return `401`; if `S` was a store-view scope, that store's data rejoins the default token's view on the next request. | Integration test: delete `core_config_data` row at `(stores, S)` manually → calls with the prior `T_s1` return `401`; calls with `T_default` now return that store's orders. |
| Scope partitioning (default-scope token) | When the presented token is bound to default scope, returned `store_id`s are exactly the complement of the set `{scope_id : core_config_data row exists at (path='myparcelnl_magento_general/api_token', scope='stores', scope_id=…)}`. | Integration test (multi-store fixture): `T_default` returns orders from all stores; generate `T_s1` → `T_default` calls no longer return store-1 orders; revoke `T_s1` → `T_default` calls return store-1 orders again. |
| Scope partitioning (store-view-scoped token) | When the presented token is bound to store-view `S`, returned records ALL satisfy `store_id = S`, regardless of URL prefix. | Integration test: `T_s1` against `/rest/V1/orders`, `/rest/default/V1/orders`, `/rest/<other_code>/V1/orders` → all return only store-1 records. |
| Existence-leak prevention | Single-record retrieval of an out-of-scope record returns `404`, not `403`, so callers cannot probe existence by status code. | Integration test: `T_s1` requesting `GET /V1/orders/<id_in_store_2>` → `404`. |
| Storage scope acceptance | Generate / clear controller accepts `scope=default` and `scope=stores`; rejects `scope=websites` and any unknown scope with `400`. | Unit test on `Controller/Adminhtml/ApiToken/Generate.php`. |

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
| Per-request overhead — auth | Single header read + single `hash('sha256', $presented)` + single SELECT from `core_config_data` (`WHERE path=… AND value=… AND scope IN ('default','stores')`) + single `IntegrationServiceInterface::findByName` (memoized). Hashing 64 input bytes is sub-microsecond. | Profile a typical request: auth-stage added latency < 2 ms at p95. |
| Per-request overhead — partition lookup | At most ONE additional SELECT from `core_config_data` per request, only when a default-scope token is presented and a list endpoint is reached, computing the carved-out `scope_id` set. Memoized on `TokenScopeContext` for the request lifetime. | Unit test: two `OrderRepositoryInterface::getList` calls in one request issue only one carved-out lookup. |
| Integration lookup | Memoized on class instance for the duration of the request (`ResetAfterRequestInterface`). | Unit test: multiple `processRequest()` calls within one request issue only one `findByName` query. |
| Allow-list lookup | In-memory hash-set check (`ScopedResourceRegistry`) — no DB roundtrip. | Code inspection. |
| Filter plugin overhead on getList | `beforeGetList` injects a single `Filter` into the existing SearchCriteria; no extra query. The added `WHERE store_id IN (…)` predicate is index-backed by `sales_order.IDX_SALES_ORDER_STORE_ID`. | Integration test on a fixture with N=10k orders: filtered getList latency within 5 % of unfiltered getList. |

## Verification Method

End-to-end via curl against a local dev store after the scaffold lands, plus unit coverage in `Tests/Unit/`.

### Test Scenarios

Multi-store fixture: a default-scope install plus two additional store-views (`store_id=1` "default", `store_id=2` "store_b", `store_id=3` "store_c"). Each store has at least one order.

1. **Install:** `bin/magento setup:upgrade && bin/magento cache:clean && bin/magento setup:di:compile` — no errors. Integration row appears in `integration`; ACL role + rules appear in `authorization_role` / `authorization_rule`.
2. **Admin generate flow (default scope):** Admin config page shows *API Access* group at default scope. Click **Generate** → token `T_default` shown in full once; `core_config_data` row at `(scope='default', scope_id=0, path='myparcelnl_magento_general/api_token')` contains a 64-char hex SHA-256 hash that does NOT equal `T_default`.
3. **Admin generate flow (store-view scope):** Switch scope selector to "store_b" → *API Access* group is visible. Click **Generate** → token `T_s2` shown in full once; `core_config_data` row at `(scope='stores', scope_id=2, path='myparcelnl_magento_general/api_token')` contains a 64-char hex SHA-256 hash that does NOT equal `T_s2`.
4. **Website scope hidden:** Switch the scope selector to "Main Website" → *API Access* group is NOT visible. Direct POST to the Generate controller with `scope=websites&scopeId=1` returns `400`.
5. **Baseline unauthenticated:** `curl -i /rest/V1/orders` → `401`.
6. **Wrong scheme:** `curl -i -H "Authorization: Bearer anything" /rest/V1/orders` → `401`.
7. **Wrong token:** `curl -i -H "Authorization: MyParcel deadbeef" /rest/V1/orders` → `401`.
8. **Default token, no store-view tokens yet:** `curl -i -H "Authorization: MyParcel <T_default>" /rest/V1/orders?searchCriteria[pageSize]=10` → `200` with orders from stores 1, 2, 3.
9. **Default token, after `T_s2` generated (partition):** with `T_s2` issued, repeat scenario 8 → `200` with orders ONLY from stores 1 and 3 (store 2 is "carved out").
10. **Store-view token narrows:** `curl -H "Authorization: MyParcel <T_s2>" /rest/V1/orders` → only store-2 orders.
11. **URL prefix is decorative:** `curl -H "Authorization: MyParcel <T_s2>" /rest/default/V1/orders` and `/rest/<store_c_code>/V1/orders` — both return only store-2 orders.
12. **Existence-leak prevention:** `curl -H "Authorization: MyParcel <T_s2>" /rest/V1/orders/<id_in_store_3>` → `404`.
13. **Custom endpoint with token scoping:** `curl -H "Authorization: MyParcel <T_s2>" "/rest/V1/myparcel/delivery-options?orderId=<id_in_store_3>"` → RFC 9457 404 (ProblemDetails).
14. **Allow-list rejects ungranted-but-scope-aware resources:** Grant a "harness" ACL resource to the integration without registering it in `ScopedResourceRegistry`. `curl -H "Authorization: MyParcel <T_default>" /rest/V1/<harness>` → `401`. Same call with admin bearer → `200` (allow-list applies only to token-authenticated calls).
15. **Allow-list parity with native ACL rejection:** `curl -H "Authorization: MyParcel <T_default>" /rest/V1/customers/1` → `401` (Magento_Customer::manage not granted; native ACL rejects).
16. **Rotation isolation:** click Generate at default scope again → `T_default` rejected; `T_s2` still works.
17. **Revocation cascade-back:** delete the `(stores, 2)` row → `T_s2` returns `401`; `T_default` calls now include store-2 orders again.
18. **Native auth unaffected:** admin bearer token continues to access all granted resources — including those NOT in `ScopedResourceRegistry`.
19. **Unit suite:** `vendor/bin/pest` green. New unit tests cover:
    - `ApiTokenUserContext::processRequest()` — header parsing, scheme casing, default-scope match, store-scope match, hash-miss → null, request without our header → null.
    - `ApiTokenManager::generate()` / `clear()` — entropy, SHA-256 hash output matches `/^[0-9a-f]{64}$/`, hash ≠ plaintext, scope-aware persistence, scope-aware clearing, config cache flush invocation.
    - `OrderRepositoryStoreFilter::beforeGetList` — default-scope-context applies `NOT IN (carvedOut)`; store-scope-context applies `= scopeId`; null-context no-ops.
    - `OrderRepositoryStoreFilter::afterGet` — out-of-scope `store_id` throws `NoSuchEntityException`; in-scope passes through.
    - `MyParcelTokenAclGate` — registry hit passes; registry miss throws `AuthorizationException` (→ 401); non-token contexts are bypassed.
    - `ScopedResourceRegistry` — registry-vs-`integration.xml` coverage regression test.

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Request` provides access to the `Authorization` header via `getHeader()`.
- The admin has permission to view/edit the MyParcel config section (existing ACL resource `MyParcelNL_Magento::myparcelnl_magento`).
- The supported scope levels are **default** and **store-view** (Magento's leaf scope, matching `sales_order.store_id`). Website-level tokens are out of scope; the Generate controller rejects them with `400`.
- A merchant who runs a single-store install (one website, one store-view) treats this feature as effectively single-tenant: a default-scope token is sufficient, no store-view tokens need to be issued, and partition behaviour is a no-op.
- The MyParcel backoffice presents the token alone for scope determination — it does NOT have to negotiate a `store_code` URL prefix or a `Store` request header to scope its calls.
- The `sales_order.store_id` foreign key (`vendor/magento/module-sales/etc/db_schema.xml`) is the authoritative partition key. Custom MyParcel endpoints that expose order-derived data MUST resolve `store_id` from the underlying `sales_order` row, not from request input.

## Constraints

- Must not require any admin CLI operation or OAuth activation.
- Must not modify any `vendor/magento/**` file.
- The custom `MyParcel` Authorization scheme must coexist peacefully with Magento's native `Bearer` scheme — other modules and admin tokens continue to work unchanged. The allow-list (`MyParcelTokenAclGate`) MUST only activate for requests whose resolved `UserContextInterface` is `ApiTokenUserContext`; native auth paths are never gated by it.
- Token expiry / TTL is explicitly out of scope for the first release; rotation is the operator's lever.
- **Supported scopes are `default` and `stores` only.** Storage, admin visibility, and runtime read for any other scope (`websites`, custom scope codes) are explicitly rejected: the Generate controller returns `400`; the admin form does not render the *API Access* group at website scope; `ApiTokenUserContext` accepts hash matches only at `scope IN ('default','stores')`.
- **Partition (not cascade) scoping is mandatory.** A store-view-scoped token MUST shadow that store from the default-scope token's view. Implementation MUST NOT use `ScopeConfigInterface::getValue` for token reads, since that API performs cascading lookups; the resolver reads `core_config_data` directly via `\Magento\Framework\App\ResourceConnection`.
- **Allow-list deny-by-default.** Every ACL resource granted in `etc/integration.xml` MUST be accompanied by (a) a `ScopedResourceRegistry` entry and (b) a per-store filter plugin on the relevant repository or the relevant custom endpoint. Adding a grant without these is a regression — caught by a coverage test.
- **Filter plugin coverage.** A per-store filter plugin MUST cover BOTH `getList`-shaped (search criteria injection) and `get(id)`-shaped (out-of-scope → `NoSuchEntityException`) paths for the resource it scopes. Custom MyParcel endpoints inheriting `AbstractEndpoint` apply the same check via `permittedStoreIds()` before exposing record data.
- **URL prefix is decorative.** `/rest/{store_code}/V1/...` MUST NOT influence what a token-authenticated call returns. The token alone determines the permitted `store_id` set.

## Implementation notes

The implementation lives across the following files inside `app/code/MyParcelNL/Magento/`:

| File | Role |
|---|---|
| `src/Model/Authorization/ApiTokenUserContext.php` | Custom `UserContextInterface` registered at `sortOrder=5`. Parses `Authorization: MyParcel <token>` (case-insensitive), hashes the presented token with SHA-256, looks up the hash via direct `core_config_data` SELECT (`path='myparcelnl_magento_general/api_token' AND value=<hash> AND scope IN ('default','stores')`), compares with `hash_equals`, and on match returns `USER_TYPE_INTEGRATION` + the auto-provisioned integration's id. Side-effect: stores `(scopeType, scopeId)` on `TokenScopeContext` for downstream filter plugins. |
| `src/Model/Authorization/TokenScopeContext.php` | Request-scoped value object implementing `ResetAfterRequestInterface`. Holds `(scopeType, scopeId)` plus a memoized `permittedStoreIds(): ?int[]`. Returns `null` when no MyParcel token authenticated this request (so filter plugins no-op). For default-scope tokens, computes the carved-out `scope_id` set by SELECT against `core_config_data WHERE path=… AND scope='stores'` (memoized). For store-view tokens, returns the singleton `[scopeId]`. |
| `src/Service/ApiTokenManager.php` | Generates / clears tokens, scope-aware. `generate(string $scopeType, int $scopeId): string` returns plaintext exactly once and persists only the SHA-256 hash at `(scopeType, scopeId)`. `clear(string $scopeType, int $scopeId): void` deletes that scope's row and flushes the config cache type. `findHashScope(string $hash): ?array` returns `[scopeType, scopeId]` or null. No `getDecryptedToken()`. |
| `src/Service/ScopedResourceRegistry.php` | DI-configured allow-list of ACL resources covered by a per-store filter plugin. Initial entries: `Magento_Sales::actions_view`, `MyParcelNL_Magento::delivery_options_read`. Provides `isCovered(string $aclResource): bool`. Adding an entry without a backing filter plugin is a contract violation flagged by tests. |
| `Controller/Adminhtml/ApiToken/Generate.php` | POST-only admin controller invoked by the *Generate* button. ACL = `MyParcelNL_Magento::myparcelnl_magento_api_token`. Accepts `scope=default` (with `scopeId=0`) or `scope=stores` (with `scopeId>0`). Refuses with `400` for `scope=websites` or any unknown scope. Returns `{ "token": "<plaintext>", "scope": "default"\|"stores", "scopeId": <int> }`. |
| `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` | Plugin on `Magento\Sales\Api\OrderRepositoryInterface`. `beforeGetList(SearchCriteria $sc)` injects `store_id IN/NOT IN (...)` based on `TokenScopeContext::permittedStoreIds()`; no-op when null. `afterGet(OrderInterface $order)` throws `NoSuchEntityException` when the loaded order's `store_id` is outside the permitted set; passes through otherwise or when context is null. |
| `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` | Plugin on `Magento\Webapi\Controller\Rest\RequestValidator::validate`. After the native `validate` accepts the request, if the resolved `UserContextInterface` is `ApiTokenUserContext` AND the route's required ACL resource is NOT in `ScopedResourceRegistry`, throws `AuthorizationException` (→ `401`). Native bearer/admin/customer auth is unaffected. |
| `src/Model/Rest/AbstractEndpoint.php` (modify) | Adds `permittedStoreIds(): ?int[]` accessor sourced from the injected `TokenScopeContext`, and a helper `assertStoreInScope(int $storeId): void` that throws `ProblemDetails` 404 when out of scope. Concrete endpoints exposing order-derived data (`OrderDeliveryOptionsV1Resource`) call it before dereferencing the order. |
| `src/Model/Rest/Resource/OrderDeliveryOptionsV1Resource.php` (modify) | Calls `assertStoreInScope($order->getStoreId())` before serializing. |
| `src/Block/System/Config/Form/ApiTokenField.php` + `view/adminhtml/templates/api_token_field.phtml` | Frontend model rendering masked input + Generate button + small AJAX snippet. Reads the current scope from the admin context so it manages the right token row; one render per scope (default and store-view; not website). |
| `etc/integration.xml` | Auto-provisions the inactive "MyParcel API" integration on `setup:upgrade` and grants its ACL resources (`Magento_Sales::actions_view`, `MyParcelNL_Magento::delivery_options_read`). |
| `etc/webapi_rest/di.xml` | Registers `ApiTokenUserContext` in `CompositeUserContext` at `sortOrder=5`; registers `OrderRepositoryStoreFilter` plugin; registers `MyParcelTokenAclGate` plugin on `RequestValidator`; declares `ScopedResourceRegistry` as a `virtualType` with the initial allow-list. |
| `etc/adminhtml/system.xml` (modify) | Adds the `api_access` group under `myparcelnl_magento_dynamic_settings` with `showInDefault=1, showInStore=1, showInWebsite=0`. The admin-visible `<comment>` warns the token is shown once and that the integration is auto-managed; mentions per-scope semantics. |
| `etc/acl.xml` | Adds the `MyParcelNL_Magento::myparcelnl_magento_api_token` resource guarding the Generate controller. |
| `etc/module.xml` (modify) | Adds `Magento_Webapi`, `Magento_Integration`, `Magento_Authorization`, `Magento_Backend`, `Magento_Config`, `Magento_Store` to the `<sequence>`. |
| `composer.json` (modify) | Adds explicit `require` entries for `magento/module-integration`, `magento/module-authorization`, `magento/module-webapi`, `magento/module-config`, `magento/module-store`. |
| `Tests/Unit/Model/Authorization/ApiTokenUserContextTest.php` | Header parsing, scheme casing, default-scope match, store-scope match, hash-miss → null, no-header → null. |
| `Tests/Unit/Service/ApiTokenManagerTest.php` | Entropy, hash output, hash ≠ plaintext, scope-aware `generate`/`clear`, `findHashScope` returns the right scope, config cache flush invocation. |
| `Tests/Unit/Plugin/Magento/Sales/OrderRepositoryStoreFilterTest.php` | Default-scope context applies `NOT IN`; store-scope context applies `=`; null context is a no-op for both `beforeGetList` and `afterGet`; `afterGet` throws `NoSuchEntityException` for out-of-scope orders. |
| `Tests/Unit/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGateTest.php` | Token + registry hit → pass; token + registry miss → 401; non-token user context → bypass; missing ACL resource on route → conservative bypass (let native validator handle). |
| `Tests/Unit/Service/ScopedResourceRegistryCoverageTest.php` | Parses `etc/integration.xml` and asserts every granted ACL resource is in `ScopedResourceRegistry` (regression test against silent leaks). |

Implementation tickets derive from US-000001..5 and trace back to this TR's §Specifications criteria tables. The §Related ADRs section above lists what each PHPDoc / XML class-header comment must cover when those files are written.
