# US-000005: Admin Generates Store-Scoped API Access Token

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Story

As a **Magento shop admin running a multi-store install**,
I want **to issue a separate MyParcel API access token for a specific store-view, so that this store's orders and delivery options become invisible to the default-scope token and remain visible only via the store-view-scoped token**,
So that **I can give MyParcel access per shop, partition my customers' data across MyParcel accounts, and rotate or revoke that store-view's access without affecting any other store**.

## Acceptance Criteria

### Scenario 1: Issuing a store-view-scoped token requires switching the scope selector

**Given** a multi-store install with stores 1 (default), 2 ("store_b"), and 3 ("store_c"),
**And** a default-scope token `T_default` that already returns orders from all three stores,
**When** I switch the admin scope selector from "Default Config" to **"store_b"** and open *MyParcel → Settings → API Access*,
**Then** the *API Access* group is visible at this store-view scope,
**And** the masked token field shows no token has been issued yet for store-view 2.

### Scenario 2: Generating a token at store-view scope shows the plaintext exactly once

**Given** I am viewing the *API Access* group at store-view 2,
**When** I click *Generate*,
**Then** a 64-character lowercase hex token `T_s2` is displayed in full exactly once,
**And** a `core_config_data` row is created at `(scope='stores', scope_id=2, path='myparcelnl_magento_general/api_access_token')` containing the SHA-256 hash of `T_s2` (NOT the plaintext),
**And** after navigating away and returning, the field at store-view 2 shows a masked placeholder.

### Scenario 3: The default-scope token's view loses store 2 immediately (partition)

**Given** I have just generated `T_s2` at store-view 2 (Scenario 2),
**And** the config cache type covering `core_config_data` reads has been flushed (which the Generate flow does automatically),
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_default>` to `GET /rest/V1/orders?searchCriteria[pageSize]=100`,
**Then** the response is `200 OK` with orders from stores 1 and 3 only,
**And** no order with `store_id = 2` appears in the response.

### Scenario 4: The store-view-scoped token returns only its store's data

**Given** the same setup as Scenario 3,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_s2>` to `GET /rest/V1/orders?searchCriteria[pageSize]=100`,
**Then** the response is `200 OK` with orders only from store 2,
**And** the same call against `GET /rest/default/V1/orders` and `GET /rest/<store_3_code>/V1/orders` (varying the URL store-code prefix) returns an identical, store-2-only result set.

### Scenario 5: Single-record requests across the partition return 404, not 403

**Given** an order `O3` in store 3 with id `<id3>`,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_s2>` to `GET /rest/V1/orders/<id3>`,
**Then** the response is `404 Not Found`,
**And** no field of the order is exposed in the response body.

### Scenario 6: Custom delivery-options endpoint respects the partition

**Given** the merged module ships `GET /V1/myparcel/delivery-options` (per `feat/dedicated-delivery-options-endpoint`),
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_s2>` and requests delivery options for an order in store 3,
**Then** the response is RFC 9457 `404` (`application/problem+json`) with no delivery-options data,
**And** the same request for an order in store 2 returns `200 OK` with delivery options.

### Scenario 7: Revoking the store-view token cascades store 2 back into the default token's view

**Given** Scenarios 3 and 4 have been verified,
**When** I clear `T_s2` (delete the `(stores, 2)` row, then flush the config cache type),
**Then** `Authorization: MyParcel <T_s2>` against any granted endpoint returns `401 Unauthorized`,
**And** the very next call with `T_default` returns orders from all three stores including store 2.

### Scenario 8: Store-tier token carves a single store-view out of a parent website-scope token (3-tier partition)

**Given** a multi-website install with W1 containing stores 1 and 2 and W2 containing stores 3 and 4,
**And** a website-scope token `T_W1` already issued at W1, currently authenticating calls for both stores 1 and 2,
**When** I switch the admin scope selector to "store_b" (store-view 2 ∈ W1) and click *Generate*,
**Then** a fresh store-tier token `T_s2` is displayed exactly once,
**And** a `core_config_data` row is written at `(scope='stores', scope_id=2, path='myparcelnl_magento_general/api_access_token')`.

**Given** that setup,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W1>` to `GET /rest/V1/orders`,
**Then** the response contains orders only from store 1 — store 2 is owned by the more-specific store-tier row and is invisible to `T_W1`.

**Given** the same setup,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_s2>` to the same endpoint,
**Then** the response contains orders only from store 2.

**Given** the same setup,
**When** I clear the `(stores, 2)` row,
**Then** subsequent calls with the (now invalid) `T_s2` return `401`,
**And** subsequent calls with `T_W1` again include store 2's orders (it has rejoined the website-tier owner).

### Scenario 9: A disabled store-view remains in its store-tier token's view

**Given** Scenarios 2 and 3 have established `T_s2` covering store 2,
**When** an admin disables store-view 2 in the admin (sets `is_active=0`),
**Then** subsequent calls with `T_s2` continue to return store-2 orders that already exist,
**And** no new orders for store 2 can be created (the store is disabled storefront-side),
**And** re-enabling store 2 has no observable effect on the permitted set (it was already covered).

### Scenario 10: Generating a store-tier token whose hash collides with another scope is rejected at write time

**Given** a default-scope token `T_default` is already issued,
**When** I attempt to generate a store-tier token whose random bytes happen to produce the same SHA-256 hash as `T_default` (forced via test seam),
**Then** the response is `409 Conflict` with a clear admin-visible message,
**And** no `core_config_data` row is written at `(stores, 2)`,
**And** no plaintext is shown (no token has been minted).

## Story Points

**Estimate:** 5
**Complexity:** High

## Technical Notes

- This story is the multi-store walkthrough that ties together the admin-side generation flow (US-000001) and the caller-side filtering rules (US-000004). Companion story US-000006 covers website-scope generation. Implementation does not introduce new files beyond those listed in TR-000004 §Implementation notes; this story exists to trace the partition (3-tier, row-coordinate) and cascade-back semantics end-to-end as testable scenarios for the store-view tier specifically.
- The "config cache flush" step in Scenarios 3 and 7 is performed by `ApiAccessTokenManager::generate()` and `clear()` respectively — see TR-000004 §Constraints "Three-tier partition" and §Performance Criteria "Per-request overhead — partition lookup".
- Admin-visible copy in `<comment>` for the *API Access* group at store-view scope must mention that issuing a token at this scope removes the store from any parent-website token's view AND from the default-scope token's view (the store is now exclusively owned by its store-tier row).
- Scenario 10's "test seam" for forcing a hash collision should be a swap-in `RandomBytesGenerator` accepted via DI; production wiring uses `random_bytes(32)`.

## Dependencies

- US-000001 (the Generate flow at any supported scope).
- US-000003 (revocation flow with cascade-back).
- US-000004 (REST caller authentication with scoped filtering and allow-list).
- Multi-store fixture in the test suite (`Tests/Unit/...`) and a multi-store local install for end-to-end verification.

## Definition of Done

- [ ] All ten scenarios above pass on a multi-website local install (W1 with stores 1 and 2; W2 with stores 3 and 4).
- [ ] Integration test (Pest) for the partition rule: with `T_default` + `T_W1` + `T_s2` issued, `getList` calls with each token produce the expected, disjoint result sets.
- [ ] Integration test for store-tier carve-out from website-tier owner: clearing `(stores, 2)` makes store 2 rejoin `T_W1`'s result set on the very next request.
- [ ] Integration test for hash uniqueness: forcing a collision at write time returns `409` with a clear admin-visible message; no row is written.
- [ ] Integration test that the URL store-code prefix is decorative for token-authenticated calls.
- [ ] Admin UI screenshot or click-test confirming the *API Access* group is visible at default, website, and store-view scopes.
- [ ] Documentation updated (this US, US-000006, FR-000005, TR-000004 cross-references).
