# US-000006: Admin Generates Website-Scoped API Access Token

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Story

As a **Magento shop admin running a multi-website install**,
I want **to issue a single MyParcel API access token that covers all store-views in one website at once, while still letting me carve out individual store-views with their own dedicated tokens**,
So that **I can hand a website's worth of stores to one MyParcel account without minting a token per store, and still keep the freedom to peel off any single store-view to a separate token later — without affecting my other websites or the default-scope token's view of them**.

## Acceptance Criteria

### Scenario 1: Issuing a website-scoped token requires switching the scope selector to a website

**Given** a multi-website install with website W1 ("Main Website", `website_id=1`) containing stores 1 and 2, and website W2 ("Secondary Website", `website_id=2`) containing stores 3 and 4,
**And** a default-scope token `T_default` that already returns orders from all four stores,
**When** I switch the admin scope selector from "Default Config" to **"Main Website"** and open *MyParcel → Settings → API Access*,
**Then** the *API Access* group is visible at this website scope,
**And** the masked token field shows no token has been issued yet for website 1.

### Scenario 2: Generating a token at website scope shows the plaintext exactly once

**Given** I am viewing the *API Access* group at website 1,
**When** I click *Generate*,
**Then** a 64-character lowercase hex token `T_W1` is displayed in full exactly once,
**And** a `core_config_data` row is created at `(scope='websites', scope_id=1, path='myparcelnl_magento_general/api_access_token')` containing the SHA-256 hash of `T_W1` (NOT the plaintext),
**And** after navigating away and returning, the field at website 1 shows a masked placeholder.

### Scenario 3: The default-scope token's view loses every store-view in W1 (website-tier partition)

**Given** I have just generated `T_W1` at website 1 (Scenario 2),
**And** the config cache type covering `core_config_data` reads has been flushed (which the Generate flow does automatically),
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_default>` to `GET /rest/V1/orders?searchCriteria[pageSize]=100`,
**Then** the response is `200 OK` with orders only from stores 3 and 4 (W2),
**And** no order with `store_id ∈ {1, 2}` appears in the response — the entire W1 is owned by the website-tier row and is invisible to `T_default`.

### Scenario 4: The website-scoped token returns its website's stores' data

**Given** the same setup as Scenario 3,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W1>` to `GET /rest/V1/orders?searchCriteria[pageSize]=100`,
**Then** the response is `200 OK` with orders from stores 1 and 2 only,
**And** the same call against `GET /rest/default/V1/orders` and `GET /rest/<store_c_code>/V1/orders` (varying the URL store-code prefix to a store outside W1) returns an identical, W1-only result set — the URL prefix is decorative for token-authenticated calls.

### Scenario 5: Single-record requests across the partition return 404, not 403

**Given** an order `O3` in store 3 (W2) with id `<id3>`,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W1>` to `GET /rest/V1/orders/<id3>`,
**Then** the response is `404 Not Found`,
**And** no field of the order is exposed in the response body.

### Scenario 6: A store-tier token within a website carves that store out of the website-tier owner

**Given** Scenarios 3 and 4 have been verified (`T_default` and `T_W1` issued),
**And** I switch the admin scope selector to "store_b" (store-view 2 ∈ W1) and click *Generate*, producing `T_s2`,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W1>` to `GET /rest/V1/orders`,
**Then** the response contains orders only from store 1 — store 2 is owned by the more-specific store-tier row.

**Given** the same setup,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_s2>` to the same endpoint,
**Then** the response contains orders only from store 2.

**Given** the same setup,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_default>`,
**Then** the response remains unchanged from Scenario 3 (only stores 3 and 4) — `T_default` never owned store 2 to begin with, so carving it out of W1 does not affect the default-tier view.

### Scenario 7: Revoking the website-scoped token releases its non-store-tokened stores back to the default token

**Given** Scenarios 3 through 6 have been verified (`T_default`, `T_W1`, and `T_s2` all issued),
**When** I clear `T_W1` (delete the `(websites, 1)` row, then flush the config cache type),
**Then** `Authorization: MyParcel <T_W1>` against any granted endpoint returns `401 Unauthorized`,
**And** the very next call with `T_default` returns orders from stores 1, 3, AND 4 — store 1 has rejoined `T_default`'s view (no website-tier row owns it any more, and it has no store-tier row),
**And** store 2 is still NOT in `T_default`'s view (it remains owned by `T_s2`'s `(stores, 2)` row).

### Scenario 8: Custom delivery-options endpoint respects the website-tier partition

**Given** the merged module ships `GET /V1/myparcel/delivery-options` (per `feat/dedicated-delivery-options-endpoint`) and `T_W1` is issued,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W1>` and requests delivery options for an order in store 3 (W2),
**Then** the response is RFC 9457 `404` (`application/problem+json`) with no delivery-options data,
**And** the same request for an order in store 1 (W1) returns `200 OK` with delivery options.

### Scenario 9: Issuing a website-scope token for a website with zero store-views succeeds (no-op token)

**Given** an admin has created website W3 with no store-views attached,
**When** I switch the admin scope selector to W3 and click *Generate*,
**Then** a fresh token `T_W3` is displayed exactly once,
**And** a `core_config_data` row is written at `(scope='websites', scope_id=3, path='myparcelnl_magento_general/api_access_token')`.

**Given** that setup,
**When** the MyParcel backoffice presents `Authorization: MyParcel <T_W3>` to `GET /rest/V1/orders`,
**Then** the response is `200 OK` with an empty result set — `permittedStoreIds()` is `[]` because W3 contains no store-views.

### Scenario 10: Generating a website-tier token whose hash collides with another scope is rejected at write time

**Given** a default-scope token `T_default` is already issued,
**When** I attempt to generate a website-tier token whose random bytes happen to produce the same SHA-256 hash as `T_default` (forced via test seam),
**Then** the response is `409 Conflict` with a clear admin-visible message,
**And** no `core_config_data` row is written at `(websites, 1)`,
**And** no plaintext is shown (no token has been minted).

### Scenario 11: An unsupported scope (e.g. group) is still rejected with 400

**Given** I have a valid admin session,
**When** I POST directly to the Generate controller with `scope=group&scopeId=1` (Magento group scope is not supported by this feature) — or any other scope value outside `default`/`websites`/`stores`,
**Then** the response is `400 Bad Request`,
**And** no `core_config_data` row is written.

## Story Points

**Estimate:** 5
**Complexity:** High

## Technical Notes

- This story is the multi-website walkthrough for the website tier specifically. Companion story US-000005 covers the store-view tier; both stories share the row-coordinate ownership and hash-uniqueness invariants from TR-000004.
- Implementation does not introduce new files beyond those listed in TR-000004 §Implementation notes; this story exists to trace the website-tier partition + 3-way carve-out semantics end-to-end as testable scenarios.
- The "config cache flush" step in Scenarios 3 and 7 is performed by `ApiAccessTokenManager::generate()` and `clear()` respectively — see TR-000004 §Constraints "Three-tier partition" and §Performance Criteria "Per-request overhead — partition lookup".
- Admin-visible copy in `<comment>` for the *API Access* group at website scope must mention that issuing a token at this scope removes every store-view in this website from the default-scope token's view, and that any store-view in this website with its own dedicated token is invisible to this website-tier token.
- Scenario 10's "test seam" for forcing a hash collision should be a swap-in `RandomBytesGenerator` accepted via DI; production wiring uses `random_bytes(32)`.
- Scenario 9 (empty-website token) is operationally surprising but valid; the admin UI does NOT require the website to have store-views before allowing generation. Documented as Assumption in TR-000004.

## Dependencies

- US-000001 (the Generate flow at any supported scope).
- US-000003 (revocation flow with cascade-back).
- US-000004 (REST caller authentication with scoped filtering and allow-list).
- US-000005 (companion story: store-view-tier carve-out within a website).
- Multi-website fixture in the test suite (`Tests/Unit/...`) and a multi-website local install for end-to-end verification.

## Definition of Done

- [ ] All eleven scenarios above pass on a multi-website local install (W1 with stores 1 and 2; W2 with stores 3 and 4; optionally W3 with zero stores).
- [ ] Integration test (Pest) for the website-tier partition: with `T_default` + `T_W1` issued, `getList` calls with each token produce the expected, disjoint result sets.
- [ ] Integration test for 3-way carve-out: with `T_default` + `T_W1` + `T_s2` issued, `getList` calls with each token produce three disjoint result sets ({stores 3,4}, {store 1}, {store 2}).
- [ ] Integration test for website-tier cascade-back: clearing `(websites, 1)` makes W1's non-store-tokened stores rejoin `T_default` on the very next request.
- [ ] Integration test for hash uniqueness: forcing a collision at write time returns `409` with a clear admin-visible message; no row is written.
- [ ] Integration test for empty-website token: token authenticates but returns empty result sets.
- [ ] Integration test that the URL store-code prefix is decorative for token-authenticated calls at the website tier.
- [ ] Admin UI screenshot or click-test confirming the *API Access* group is visible at website scope and shows the appropriate `<comment>` copy.
- [ ] Documentation updated (this US, US-000005, FR-000005, TR-000004 cross-references).
