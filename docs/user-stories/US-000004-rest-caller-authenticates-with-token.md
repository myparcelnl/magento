# US-000004: REST Caller Authenticates with Token

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Story

As the **MyParcel SaaS backoffice service**,
I want **to authenticate to a customer's Magento REST API using the issued token in the `Authorization` header, with the response automatically scoped to the stores covered by that specific token**,
So that **I can read order and delivery-options data without an OAuth flow, token refresh, or per-customer wiring, and so that a token issued for one store cannot read data from another store**.

## Acceptance Criteria

### Scenario 1: Missing Authorization header is rejected

**Given** a Magento store with a generated token,
**When** the caller sends `GET /rest/V1/orders` with no `Authorization` header,
**Then** the response is `401 Unauthorized`,
**And** no order data is returned.

### Scenario 2: Wrong scheme is rejected

**Given** a Magento store with a generated token T,
**When** the caller sends `GET /rest/V1/orders` with `Authorization: Bearer <T>` (or `Authorization: Bearer anything`),
**Then** the response is `401 Unauthorized`,
**And** the `MyParcel` UserContext returns `null` (does not match), allowing the native Bearer chain to handle the request — which itself rejects because T is not a valid Magento integration access token.

### Scenario 3: Wrong token under the correct scheme is rejected

**Given** a Magento store with a generated token T,
**When** the caller sends `GET /rest/V1/orders` with `Authorization: MyParcel deadbeef`,
**Then** the response is `401 Unauthorized`,
**And** comparison fails because `hash('sha256', 'deadbeef')` does not equal the stored hash.

### Scenario 4: Default-scope token unlocks granted resources for non-carved-out stores

**Given** a multi-store install with stores 1, 2, 3 and a default-scope token `T_default` (no store-view tokens issued yet),
**When** the caller sends `GET /rest/V1/orders?searchCriteria[pageSize]=100` with `Authorization: MyParcel <T_default>`,
**Then** the response is `200 OK` with orders from stores 1, 2, AND 3 (none are carved out).

### Scenario 4a: Default-scope token excludes stores that have their own dedicated token (partition rule)

**Given** a multi-store install with stores 1, 2, 3 and tokens `T_default` and `T_s2` (issued at store-view 2),
**When** the caller sends `GET /rest/V1/orders?searchCriteria[pageSize]=100` with `Authorization: MyParcel <T_default>`,
**Then** the response is `200 OK` with orders from stores 1 and 3 only — store 2's orders are not returned.

### Scenario 4a-bis: Default-scope token excludes stores covered by a website-scope token (3-tier partition)

**Given** a multi-website install with website W1 containing stores 1 and 2, and website W2 containing stores 3 and 4, and tokens `T_default` and `T_W1` (issued at website W1),
**When** the caller sends `GET /rest/V1/orders?searchCriteria[pageSize]=100` with `Authorization: MyParcel <T_default>`,
**Then** the response is `200 OK` with orders from stores 3 and 4 only — both of W1's stores are carved out by the website-tier row, even though no store-tier row exists.

### Scenario 4b: Store-view-scoped token returns only that store's records, regardless of URL prefix

**Given** a token `T_s2` issued at store-view 2,
**When** the caller sends `GET /rest/V1/orders` with `Authorization: MyParcel <T_s2>`,
**Or** `GET /rest/default/V1/orders` with the same token,
**Or** `GET /rest/<store_3_code>/V1/orders` with the same token,
**Then** every response is `200 OK` containing only orders with `store_id = 2`,
**And** the URL store-code prefix has no effect on which records are returned (decorative for token-authenticated calls).

### Scenario 4b-bis: Website-scoped token returns its website's stores minus store-tier carve-outs

**Given** the multi-website install from Scenario 4a-bis, with `T_W1` already issued; and additionally a store-tier token `T_s2` issued at store-view 2 (s2 ∈ W1),
**When** the caller sends `GET /rest/V1/orders` with `Authorization: MyParcel <T_W1>`,
**Then** the response is `200 OK` with orders ONLY from store 1 — store 2 is owned by the more-specific store-tier row and is invisible to the website-tier token.

**Given** the same setup,
**When** the caller sends the same request with `Authorization: MyParcel <T_s2>`,
**Then** the response contains orders only from store 2.

**Given** the same setup,
**When** the admin revokes `T_s2` (deletes the `(stores, 2)` row),
**Then** subsequent calls with the (now invalid) `T_s2` return `401`,
**And** subsequent calls with `T_W1` again include store 2's orders (it has rejoined the website-tier owner).

### Scenario 4c: Single-record retrieval of an out-of-scope record returns 404 (no existence leak)

**Given** a token `T_s2` issued at store-view 2 and an order `O3` whose `store_id` is 3,
**When** the caller sends `GET /rest/V1/orders/<id of O3>` with `Authorization: MyParcel <T_s2>`,
**Then** the response is `404 Not Found`, NOT `403 Forbidden`,
**And** the response body does not reveal whether the order exists in another store.

### Scenario 5: Correct token does not unlock ungranted resources (native ACL rejection)

**Given** a Magento store with a generated token T,
**When** the caller sends `GET /rest/V1/customers/1` with `Authorization: MyParcel <T>` (the integration does not grant `Magento_Customer::manage`),
**Then** the response is `401 Unauthorized`,
**And** ACL enforcement at `Magento\Webapi\Controller\Rest\RequestValidator::checkPermissions` rejects the request before the controller runs.

### Scenario 5a: Correct token does not unlock granted-but-not-allow-listed resources (deny-by-default)

**Given** a Magento store with a generated token `T` and an experimental ACL resource `MyParcelNL_Magento::experimental` that has been granted to the integration but is NOT registered in `ScopedResourceRegistry`,
**When** the caller sends `GET /rest/V1/myparcel/experimental` with `Authorization: MyParcel <T>`,
**Then** the response is `401 Unauthorized`,
**And** the rejection comes from the `MyParcelTokenAclGate` plugin, not from native ACL.

**Given** the same setup,
**When** an admin authenticates with a Magento admin token via `Authorization: Bearer <admin-token>` and calls the same endpoint,
**Then** the response is `200 OK` — the allow-list applies only to MyParcel-scheme token-authenticated calls.

### Scenario 6: Custom scheme is case-insensitive

**Given** a Magento store with a generated token T,
**When** the caller varies the scheme casing — `MyParcel`, `myparcel`, `MYPARCEL`, `MyPaRcEl` — with the same token T,
**Then** every variant returns `200 OK` for granted resources,
**And** none of them accidentally match the literal string `Bearer`.

### Scenario 7: Magento bearer-gate flag does not affect us

**Given** the Magento install has `oauth/consumer/enable_integration_as_bearer = 0` (default),
**When** the MyParcel-scheme token is presented to a granted resource,
**Then** the response is `200 OK` regardless of the flag value (the flag gates the native Bearer path; we use a custom scheme that bypasses it cleanly).

### Scenario 8: Custom endpoint integration with token-scoped filtering

**Given** the module also ships `GET /V1/myparcel/delivery-options` (delivered separately on `feat/dedicated-delivery-options-endpoint`) gated by `MyParcelNL_Magento::delivery_options_read`,
**When** the merged module ships and the caller presents `Authorization: MyParcel <T_default>` to that endpoint for an order in a non-carved-out store,
**Then** the response is `200 OK` with delivery options for the requested order — no separate token, no additional grant by the admin.

**Given** the same module,
**When** the caller presents `Authorization: MyParcel <T_s2>` to that endpoint for an order whose `store_id` is 3 (not in scope),
**Then** the response is RFC 9457 `404` (`application/problem+json`) with no delivery-options data leaked,
**And** the same call for an order whose `store_id` is 2 returns `200 OK` with delivery options.

## Story Points

**Estimate:** 5
**Complexity:** High

## Technical Notes

- Implementation lives in `src/Model/Authorization/ApiAccessTokenUserContext.php` (custom UserContext at `sortOrder=5` in `CompositeUserContext`), `src/Model/Authorization/TokenScopeContext.php` (request-scoped scope state), `src/Service/ScopedResourceRegistry.php` (allow-list), `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` (per-store filter), `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` (deny-by-default gate), `etc/webapi_rest/di.xml` (registration), and `etc/integration.xml` (auto-provisioned integration for ACL grants).
- Per TR-000004 §Specifications: storage compares SHA-256 hashes via `hash_equals` (constant-time); the UserContext SELECTs against `core_config_data` directly with `scope IN ('default','websites','stores')` (NOT via `ScopeConfigInterface`, which would cascade); ACL enforcement combines Magento-native install-wide grants with the module's `ScopedResourceRegistry` allow-list and the per-resource filter plugins; per-store membership is row-coordinate based, not hash-value based, so duplicate hashes across rows cannot conflate ownership.
- Critical files in `vendor/magento/**` that this UserContext and its plugins interact with — see PHPDoc on each class (added when the file is created).

## Dependencies

- Hard dependency on US-000001 (storage layer must exist).
- Soft dependency: scenario 8 specifically becomes verifiable only after `feat/dedicated-delivery-options-endpoint` merges.

## Definition of Done

- [ ] Unit tests for `ApiAccessTokenUserContext::processRequest()` cover: missing header, Bearer, lowercase scheme, mismatched token, empty storage, default-scope match, website-scope match, store-view scope match.
- [ ] Unit tests for `TokenScopeContext::permittedStoreIds()` cover the three-tier ownership matrix (`stores > websites > default`), disabled-store inclusion, admin-store exclusion, null when no token authenticated this request, and bulk row-set memoization.
- [ ] Unit tests for `OrderRepositoryStoreFilter` cover: for each scope tier, `beforeGetList` applies `IN(permittedSet)` matching `TokenScopeContext::permittedStoreIds()`; null context no-ops; `afterGet` throws `NoSuchEntityException` for out-of-scope orders.
- [ ] Unit tests for `MyParcelTokenAclGate` cover: registry hit passes; registry miss returns 401 for token caller; non-token contexts bypass.
- [ ] Integration tests run all scenarios above against a multi-website local dev store (W1 with stores 1 and 2; W2 with stores 3 and 4).
- [ ] Native Bearer auth, customer auth, and other modules' UserContexts verified unaffected (regression check via the composite chain).
- [ ] `ScopedResourceRegistry` coverage test ensures every `etc/integration.xml` grant is registered.
- [ ] No `vendor/magento/**` file is modified.
- [ ] `bin/magento setup:upgrade && setup:di:compile` is clean.