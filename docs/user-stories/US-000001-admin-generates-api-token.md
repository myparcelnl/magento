# US-000001: Admin Generates API Token in One Click

## Parent Functional Requirement

- **FR:** [FR-000005 — Self-service API token for MyParcel REST integration](../functional-requirements/FR-000005-self-service-api-token.md)

## Story

As a **Magento shop admin**,
I want **to generate a MyParcel API token from a single button in the MyParcel admin config — at default scope, or at a specific store-view scope on a multi-store install**,
So that **I can connect MyParcel to my store without using the CLI or activating an integration, and so I can issue separate tokens per store-view when I need to keep stores' data partitioned**.

## Acceptance Criteria

### Scenario 1: Fresh install shows the API Access group at default scope

**Given** a Magento install where `bin/magento setup:upgrade` has run with this module enabled,
**And** I am logged in as an admin with permission on `MyParcelNL_Magento::myparcelnl_magento`,
**When** I open *Stores → Configuration → MyParcel → Settings → API Access* in the default scope,
**Then** I see a group titled "API Access" with explanatory comment text and a *Generate* button.

### Scenario 2: Generating a token at the current scope shows the plaintext exactly once

**Given** I am viewing the *API Access* group at the current admin scope (default OR a specific store-view),
**When** I click *Generate*,
**Then** the page displays a 64-character lowercase hex token in full,
**And** a notice instructs me to copy it now ("Token generated. Copy it now — it will not be shown again."),
**And** a `core_config_data` row at path `myparcelnl_magento_general/api_token` exists for that exact `(scope, scopeId)` pair (`('default',0)` or `('stores',<storeId>)`) with a 64-char hex SHA-256 hash value that is NOT equal to the displayed token.

### Scenario 3: Plaintext is not recoverable after page reload

**Given** I have just generated a token at the current scope and the plaintext is visible,
**When** I navigate away from the configuration page and return,
**Then** the API token field displays a masked placeholder (`••••••••`) for that scope,
**And** there is no UI control to re-display the previously-issued plaintext,
**And** no server-side code path can read or return the plaintext (only the SHA-256 hash exists in storage).

### Scenario 4: API Access group is visible at default and store-view scopes, hidden at website scope

**Given** I have multiple websites and store-views configured,
**When** I switch the admin scope switcher from "Default Config" to a **store-view** scope,
**And** I open *MyParcel → Settings*,
**Then** the *API Access* group **is** visible and shows a Generate button bound to that store-view's token row.

**Given** I have multiple websites and store-views configured,
**When** I switch the admin scope switcher to a **website** scope (e.g., "Main Website"),
**And** I open *MyParcel → Settings*,
**Then** the *API Access* group **is not** visible,
**And** there is no way to generate, view, or modify a token at website scope.

### Scenario 5: Direct controller request at website scope is rejected

**Given** I have a valid admin session and CSRF/form key,
**When** I POST to the Generate controller with `scope=websites&scopeId=<any>`,
**Then** the response is `400 Bad Request`,
**And** no row is written to `core_config_data`.

## Story Points

**Estimate:** 3
**Complexity:** Medium

## Technical Notes

- Implementation is the *Generate* admin controller (`Controller/Adminhtml/ApiToken/Generate.php`), the `ApiTokenManager` service (`src/Service/ApiTokenManager.php`), the `frontend_model` block (`src/Block/System/Config/Form/ApiTokenField.php`), and the `etc/adminhtml/system.xml` group `api_access` under `myparcelnl_magento_dynamic_settings` with `showInDefault=1, showInStore=1, showInWebsite=0`.
- The Generate controller resolves the current admin scope from the request and accepts only `scope=default` (with `scopeId=0`) or `scope=stores` (with `scopeId>0`). Anything else returns `400`.
- See TR-000004 §Specifications for token entropy, hashing primitive, and per-scope storage/partition criteria.
- The `system.xml` group `<comment>` carries the admin-visible caveats; copy is owned by TR-000004 §Implementation notes (verbatim).

## Dependencies

- US-000004 (REST caller authentication) shares the storage layer; both rely on `core_config_data` rows at path `myparcelnl_magento_general/api_token` keyed by `(scope, scopeId)`.
- US-000005 (admin generates store-scoped API token) extends this story with a partition-behaviour walkthrough.

## Definition of Done

- [ ] Admin config screen renders the *API Access* group at default and store-view scopes with `<comment>` matching TR-000004 §Implementation notes verbatim.
- [ ] Generating at the current scope produces a 64-char hex token; the corresponding `(scope, scopeId)` storage row contains a SHA-256 hash, not the plaintext.
- [ ] Reload masks the field; no server-side read of plaintext exists.
- [ ] Group is hidden at website scope.
- [ ] POST to the Generate controller with `scope=websites` returns `400` and does not write any storage row.
- [ ] Unit tests on `ApiTokenManager::generate()` cover entropy, hash output, scope-aware persistence, and idempotency for both default and store-view scopes.
- [ ] Documentation updated (this US, FR-000005, TR-000004 cross-references).
