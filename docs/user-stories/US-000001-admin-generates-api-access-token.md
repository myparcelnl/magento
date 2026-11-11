# US-000001: Admin Generates API Access Token in One Click

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API access token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-access-token.md)

## Story

As a **Magento shop admin**,
I want **to generate a MyParcel API access token from a single button in the MyParcel admin config — at default scope, at a website scope, or at a specific store-view scope on a multi-store install**,
So that **I can connect MyParcel to my store without using the CLI or activating an integration, and so I can issue separate tokens at any of the three supported scope tiers when I need to keep data partitioned across websites or store-views**.

## Acceptance Criteria

### Scenario 1: Fresh install shows the API Access group at default scope

**Given** a Magento install where `bin/magento setup:upgrade` has run with this module enabled,
**And** I am logged in as an admin with permission on `MyParcelNL_Magento::myparcelnl_magento`,
**When** I open *Stores → Configuration → MyParcel → Settings → API Access* in the default scope,
**Then** I see a group titled "API Access" with explanatory comment text and a *Generate* button.

### Scenario 2: Generating a token at the current scope shows the plaintext exactly once

**Given** I am viewing the *API Access* group at the current admin scope (default OR a specific website OR a specific store-view),
**When** I click *Generate*,
**Then** the page displays a 64-character lowercase hex token in full,
**And** a notice instructs me to copy it now ("Token generated. Copy it now — it will not be shown again."),
**And** a `core_config_data` row at path `myparcelnl_magento_general/api_access_token` exists for that exact `(scope, scopeId)` pair (`('default',0)`, `('websites',<websiteId>)`, or `('stores',<storeId>)`) with a 64-char hex SHA-256 hash value that is NOT equal to the displayed token.

### Scenario 3: Plaintext is not recoverable after page reload

**Given** I have just generated a token at the current scope and the plaintext is visible,
**When** I navigate away from the configuration page and return,
**Then** the API access token field displays a masked placeholder (`••••••••`) for that scope,
**And** there is no UI control to re-display the previously-issued plaintext,
**And** no server-side code path can read or return the plaintext (only the SHA-256 hash exists in storage).

### Scenario 4: API Access group is visible at default, website, and store-view scopes

**Given** I have multiple websites and store-views configured,
**When** I switch the admin scope switcher from "Default Config" to a **website** scope (e.g., "Main Website"),
**And** I open *MyParcel → Settings*,
**Then** the *API Access* group **is** visible and shows a Generate button bound to that website's token row.

**Given** the same install,
**When** I switch the admin scope switcher to a **store-view** scope,
**And** I open *MyParcel → Settings*,
**Then** the *API Access* group **is** visible and shows a Generate button bound to that store-view's token row.

### Scenario 5: Direct controller request at an unsupported scope is rejected

**Given** I have a valid admin session and CSRF/form key,
**When** I POST to the Generate controller with `scope=group&scopeId=<any>` (Magento group scope is not a supported scope tier for this feature) — or any other scope value outside `default` / `websites` / `stores`,
**Then** the response is `400 Bad Request`,
**And** no row is written to `core_config_data`.

### Scenario 6: Direct controller request whose hash collides with another scope is rejected

**Given** a default-scope token is already issued,
**And** I have a valid admin session and CSRF/form key,
**When** I POST to the Generate controller at any scope coordinate and the random bytes happen to produce the same SHA-256 hash as the existing default-scope row (forced via test seam),
**Then** the response is `409 Conflict` with a clear admin-visible error message,
**And** no row is written to `core_config_data`,
**And** no plaintext is returned.

## Story Points

**Estimate:** 3
**Complexity:** Medium

## Technical Notes

- Implementation is the *Generate* admin controller (`Controller/Adminhtml/ApiAccessToken/Generate.php`), the `TokenService` (`src/Service/ApiAccessToken/TokenService.php`), the `frontend_model` block (`src/Block/System/Config/Form/ApiAccessTokenButton.php`), and the `etc/adminhtml/system.xml` group `api_access` under `myparcelnl_magento_dynamic_settings` with `showInDefault=1, showInWebsite=1, showInStore=1`.
- The Generate controller resolves the current admin scope from the request and accepts only `scope=default` (with `scopeId=0`), `scope=websites` (with `scopeId>0` referring to a real website), or `scope=stores` (with `scopeId>0` referring to a real, non-admin store). Anything else returns `400`. A pre-INSERT hash-uniqueness check returns `409 Conflict` if the freshly hashed token collides with any other scope coordinate's existing row.
- See TR-000004 §Specifications for token entropy, hashing primitive, and per-scope storage/partition criteria.
- The `system.xml` group `<comment>` carries the admin-visible caveats; copy is owned by TR-000004 §Implementation notes (verbatim) and varies per scope (default / website / store-view).

## Dependencies

- US-000004 (REST caller authentication) shares the storage layer; both rely on `core_config_data` rows at path `myparcelnl_magento_general/api_access_token` keyed by `(scope, scopeId)`.
- US-000005 (admin generates store-scoped API access token) extends this story with the store-view-tier partition-behaviour walkthrough.
- US-000006 (admin generates website-scoped API access token) extends this story with the website-tier partition-behaviour walkthrough.

## Definition of Done

- [ ] Admin config screen renders the *API Access* group at default, website, and store-view scopes with the per-scope `<comment>` copy matching TR-000004 §Implementation notes verbatim.
- [ ] Generating at the current scope produces a 64-char hex token; the corresponding `(scope, scopeId)` storage row contains a SHA-256 hash, not the plaintext.
- [ ] Reload masks the field; no server-side read of plaintext exists.
- [ ] POST to the Generate controller with any scope value outside `default`/`websites`/`stores` returns `400` and does not write any storage row.
- [ ] POST to the Generate controller whose freshly hashed token collides with an existing row at another scope coordinate returns `409 Conflict` with a clear admin-visible message; no row is written.
- [ ] Unit tests on `TokenService::generateForScope()` cover entropy, hash output, scope-aware persistence at all three tiers, hash-uniqueness rejection (409), and idempotency.
- [ ] Documentation updated (this US, US-000005, US-000006, FR-000005, TR-000004 cross-references).
