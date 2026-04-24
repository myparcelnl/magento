# TR-000004: REST API Token Authentication

> **Status:** Draft — recovered from planning session on 2026-04-24, targeting branch `feat/rest-api-token-auth`. Iterate here.
>
> **Scaffolding note:** The `docs/` tree and template (`docs/templates/03-technical-requirements-template.md`) currently live on `feat/dedicated-delivery-options-endpoint`. TR-000001/2/3 on that branch describe the versioning infrastructure. This TR was numbered `000004` to avoid collision once both branches reconcile.

## Related Functional Requirements

- _FR to be written_ — this TR introduces a new capability ("MyParcel backoffice authenticates to a customer's Magento REST API without any admin CLI step"). Candidate FR title: _"MyParcel backoffice authenticates against customer Magento REST API"_.

## Related ADRs

- _ADR to be written_ — the structural decision (custom `UserContextInterface`, `Authorization: MyParcelNL <token>` scheme, storage in `core_config_data`, bypassing Magento's `oauth/consumer/enable_integration_as_bearer` gate) is ADR-shaped. Source material for that ADR is preserved verbatim in §"Approach" and §"What we are deliberately NOT building" below.

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

Baseline verified: `vendor/magento/module-webapi/Controller/Rest/RequestValidator.php:104` `checkPermissions` had a temporary `return;` that has been reverted; native ACL enforcement is working normally.

## Specifications

### Security Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Token entropy | 256 bits of OS-sourced randomness (`random_bytes(32)`) | Code inspection of `ApiTokenManager::generate()` |
| Token presentation | Hex-encoded (64 lowercase hex chars) | Unit test: `generate()` output matches `/^[0-9a-f]{64}$/` |
| Storage encryption | Encrypted at rest via `Magento\Framework\Encryption\EncryptorInterface::encrypt` | Inspection of `core_config_data.value` for row `myparcelnl_magento_general/api_token` — value is opaque ciphertext, not plaintext hex |
| Comparison | Constant-time via `hash_equals` | Code inspection of `ApiTokenUserContext::processRequest()` |
| Plaintext exposure | Returned to admin exactly once, immediately after generation; never logged, never re-readable from storage | Unit test: `generate()` returns plaintext but config stores ciphertext; admin UI on reload shows masked placeholder only |
| Scheme | `Authorization: MyParcelNL <token>` (case-insensitive scheme) | Unit test: `Bearer`, empty, malformed, and other schemes all bail (userType=null) |
| ACL enforcement | Requests with valid token but resource not in integration grants return `401` | Integration test: token + `GET /V1/customers/1` (no `Magento_Customer::manage` grant) → `401` |
| Rotation | Generating a new token invalidates the previous one immediately | Integration test: generate → capture T1 → generate → T1 returns `401`, T2 returns `200` |
| Revocation | Clearing the config row causes all subsequent requests to `401` | Integration test: delete row manually → any token value returns `401` |

### Compatibility Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| UserContext registration | Custom context registered at `sortOrder=5` in `CompositeUserContext` chain, ahead of native `tokenUserContext` (`sortOrder=10`) | `bin/magento object-manager:debug Magento\Authorization\Model\CompositeUserContext` or equivalent inspection — `myParcelTokenUserContext` appears first in array |
| Bypass safety when token absent | When no `Authorization: MyParcelNL` header is present, context returns `(userType=null, userId=null)` and the composite chain falls through to native contexts unchanged | Unit test: request without our header → our context returns nulls; native contexts process normally |
| Magento version | Works on Magento 2.4.4+ regardless of `oauth/consumer/enable_integration_as_bearer` value | Integration test: with flag both `0` (default) and `1`, our scheme works identically |
| ACL grant mechanism | Integration role + rules created by Magento's own `Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations → grantPermissions` on `setup:upgrade` | Integration test: fresh install → `authorization_role` has row `(user_type=1, user_id=<integration id>)`; `authorization_rule` has rows for each resource in `etc/integration.xml` |
| Forward-compatible grants | Granting a resource that doesn't yet exist in any `acl.xml` is a no-op; becomes effective automatically once the resource is defined by a later release | `grantPermissions` writes resource IDs as plain strings into `authorization_rule` — no foreign key to `acl.xml`. Verified in `Magento\Integration\Model\AuthorizationService::grantPermissions()` |

### Performance Criteria

| Criterion | Requirement | Measurement Method |
|-----------|-------------|---------------------|
| Per-request overhead | Single header read + single `scopeConfig->getValue` + single `decrypt` + single `IntegrationServiceInterface::findByName` (memoized after first call) | Profile a typical request: total added latency < 2 ms at p95 |
| Integration lookup | Memoized on class instance for the duration of the request (`ResetAfterRequestInterface`) | Unit test: multiple `processRequest()` calls within one request issue only one `findByName` query |

## Verification Method

End-to-end via curl against a local dev store after the scaffold lands, plus unit coverage in `Tests/Unit/`.

### Test Scenarios

1. **Install:** `bin/magento setup:upgrade && bin/magento cache:clean && bin/magento setup:di:compile` — no errors. Integration row appears in `integration`; ACL role + rules appear in `authorization_role` / `authorization_rule`.
2. **Admin generate flow:** Admin config page shows *API Access* group. Click **Generate** → token shown in full once; `core_config_data` row `myparcelnl_magento_general/api_token` contains encrypted ciphertext.
3. **Baseline unauthenticated:** `curl -i http://shop.test/rest/V1/orders` → `401`.
4. **Wrong scheme:** `curl -i -H "Authorization: Bearer anything" http://shop.test/rest/V1/orders` → `401`.
5. **Wrong token:** `curl -i -H "Authorization: MyParcelNL deadbeef" http://shop.test/rest/V1/orders` → `401`.
6. **Correct token, granted resource:** `curl -i -H "Authorization: MyParcelNL <token>" "http://shop.test/rest/V1/orders?searchCriteria[pageSize]=1"` → `200` with order list.
7. **Correct token, custom endpoint:** (requires `feat/dedicated-delivery-options-endpoint` merged) `curl -i -H "Authorization: MyParcelNL <token>" "http://shop.test/rest/V1/myparcel/delivery-options?orderId=1"` → `200`.
8. **Correct token, ungranted resource:** `curl -i -H "Authorization: MyParcelNL <token>" http://shop.test/rest/V1/customers/1` → `401` (ACL rejects because `Magento_Customer::manage` not granted).
9. **Rotation:** click Generate again. Old token → `401`. New token → `200`.
10. **Revocation:** Clear the `core_config_data` row manually; any token → `401` (decrypt returns null, comparison fails).
11. **Unit suite:** `vendor/bin/pest` green. New unit tests for `ApiTokenUserContext::processRequest()` (header parsing, match/mismatch, empty storage) and `ApiTokenManager::generate()` (entropy, encryption round-trip, idempotency).

## Assumptions

- Magento's `\Magento\Framework\Webapi\Rest\Request` provides access to the `Authorization` header via `getHeader()`.
- The encryption key (`crypt/key` in `app/etc/env.php`) is stable across the store's lifetime — rotation of the Magento encryption key would invalidate stored tokens and require re-generation (same as any other encrypted config value).
- The admin has permission to view/edit the MyParcel config section (existing ACL resource `MyParcelNL_Magento::myparcelnl_magento`).
- One token per shop is sufficient for MyParcel's current integration model. If multi-tenant per-store tokens are later required, only the storage layer changes (table replaces config row); the UserContext contract is unaffected.

## Constraints

- Must not require any admin CLI operation or OAuth activation.
- Must not modify any `vendor/magento/**` file.
- The custom `MyParcelNL` Authorization scheme must coexist peacefully with Magento's native `Bearer` scheme — other modules and admin tokens continue to work unchanged.
- Token expiry / TTL is explicitly out of scope for the first release; rotation is the operator's lever.

---

## Implementation Plan

> Preserved from the planning session. Revise freely; extract into an ADR + separate tickets when ready.

### Approach (design summary)

Add a custom `UserContextInterface` in our module that:

1. Reads an `Authorization: MyParcelNL <token>` header (custom scheme — avoids collision with native `Bearer` while staying in the standard header).
2. Compares against an encrypted token we store in `core_config_data` (constant-time comparison via `hash_equals`).
3. On match, returns `USER_TYPE_INTEGRATION` plus the ID of a hidden, auto-provisioned integration. No match → returns nulls so the default context chain runs.

Register it in `etc/webapi_rest/di.xml` with `sortOrder="5"` (ahead of native `tokenUserContext` at `10`). `CompositeUserContext::getUserContext()` (`vendor/magento/module-authorization/Model/CompositeUserContext.php:80-95`) iterates in order and stops at the first context that returns non-null type + id, so our scheme wins cleanly whenever present.

Once our context returns `USER_TYPE_INTEGRATION` + integration ID:

- `\Magento\Framework\Authorization::isAllowed($resource)` calls `WebapiRoleLocator::getAclRoleId()` which queries `authorization_role` by `(user_id=<integration_id>, user_type=1)`. The integration does **not** need to be active — only the role + rules need to exist.
- The role + rules are created on `setup:upgrade` from our `etc/integration.xml` via `Magento\Webapi\Model\Plugin\Manager::afterProcessConfigBasedIntegrations` → `grantPermissions`. We keep the integration permanently inactive (no OAuth token ever generated) — the admin sees it in the Integrations list for transparency but doesn't interact with it.

The admin UX is a single config field with a "Generate" button in our own system.xml section. The native Integrations screen stays advisory.

### Files to add / modify (all inside `app/code/MyParcelNL/Magento/`)

#### 1. `etc/integration.xml` (new)

Auto-creates the hidden integration and grants its ACL resources on `setup:upgrade`. Schema: `urn:magento:module:Magento_Integration:etc/integration/integration.xsd`.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:module:Magento_Integration:etc/integration/integration.xsd">
    <integration name="MyParcelNL API">
        <email>noreply@myparcel.nl</email>
        <resources>
            <resource name="Magento_Sales::actions_view"/>
            <resource name="MyParcelNL_Magento::delivery_options_read"/>
        </resources>
    </integration>
</config>
```

Integration name `MyParcelNL API` is used by our UserContext to look up its ID at runtime (see §3).

#### 2. `etc/webapi_rest/di.xml` (new)

Registers our custom UserContext at the front of `CompositeUserContext`'s chain. Pattern mirrors `vendor/magento/module-webapi/etc/webapi_rest/di.xml:14-31`.

```xml
<?xml version="1.0"?>
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:ObjectManager/etc/config.xsd">
    <type name="Magento\Authorization\Model\CompositeUserContext">
        <arguments>
            <argument name="userContexts" xsi:type="array">
                <item name="myParcelTokenUserContext" xsi:type="array">
                    <item name="type" xsi:type="object">MyParcelNL\Magento\Model\Authorization\ApiTokenUserContext</item>
                    <item name="sortOrder" xsi:type="string">5</item>
                </item>
            </argument>
        </arguments>
    </type>
</config>
```

#### 3. `src/Model/Authorization/ApiTokenUserContext.php` (new)

Implements `Magento\Authorization\Model\UserContextInterface`. Responsibilities:

- Read `Authorization` header via `RequestInterface::getHeader('Authorization')`.
- Parse `MyParcelNL <token>` (case-insensitive scheme, same pattern as `TokenUserContext.php:141-151`). Any other scheme → bail (`userType=null`, `userId=null`).
- Fetch stored token: `$this->scopeConfig->getValue('myparcelnl_magento_general/api_token')` → `$this->encryptor->decrypt(...)`.
- `hash_equals($stored, $presented)` comparison.
- On match: resolve integration ID via `IntegrationServiceInterface::findByName('MyParcelNL API')->getId()` (one query, can be memoised on the class instance for the rest of the request). Set `userType = USER_TYPE_INTEGRATION`, `userId = <that id>`.
- Cache the processed flag the same way `TokenUserContext` does to avoid double-parsing.

Constructor deps: `RequestInterface`, `ScopeConfigInterface`, `EncryptorInterface`, `IntegrationServiceInterface`. Implements `ResetAfterRequestInterface` like the native context.

#### 4. `src/Service/ApiTokenManager.php` (new)

Small service used by both the generate button and (possibly) the UserContext. API:

- `generate(): string` — create new token (`bin2hex(random_bytes(32))` → 64 hex chars), encrypt with `EncryptorInterface::encrypt`, persist via `WriterInterface::save('myparcelnl_magento_general/api_token', $encrypted, 'default', 0)`, clean the config cache, return plaintext (returned only to the caller — NEVER logged).
- `getDecryptedToken(): ?string` — read from config + decrypt, or null if unset.
- `clear(): void` — delete the config value.

#### 5. `Controller/Adminhtml/ApiToken/Generate.php` (new)

POST-only admin controller invoked by the "Generate" button. ACL: add `MyParcelNL_Magento::myparcelnl_magento_api_token` to `etc/acl.xml`. Returns JSON `{ "token": "<plaintext>" }` for the frontend to display once. Plaintext is then forgotten server-side (only the encrypted version is stored).

#### 6. `etc/adminhtml/routes.xml` (extend)

Add route to the existing `myparcel` adminhtml frontName to expose `/admin/myparcel/api_token/generate` for the controller above. The router already exists (`etc/adminhtml/routes.xml` has `router id="admin" frontName="myparcel"` — confirm on current file).

#### 7. `etc/adminhtml/system.xml` (modify)

Add a new group **"API Access"** under the existing `myparcelnl_magento_dynamic_settings` section (decided — keeps the module's admin layout consistent). Two fields:

- Read-only display: "API token" — masked (`••••••••`), with a "Reveal" toggle for the last generated plaintext kept only in the current-request response payload, not re-read from storage (decrypted form shown only right after generation for copy-paste).
- Button: "Generate / Regenerate token" → JS posts to the admin controller above → displays returned plaintext in the masked field → success notice "Token regenerated. Copy it now — it will not be shown again."

Use a custom `frontend_model` that renders the button + input + small JS snippet. Pattern reference: any Magento button-in-config example (`Magento\Config\Block\System\Config\Form\Field\Regenerate` shape).

#### 8. `etc/acl.xml` (modify on merge target)

Add under the existing `MyParcelNL_Magento::myparcelnl_magento` resource:

```xml
<resource id="MyParcelNL_Magento::myparcelnl_magento_api_token" title="MyParcel API Token" sortOrder="30"/>
```

Guards the Generate controller.

#### 9. `etc/module.xml` (modify)

Add to `<sequence>`: `Magento_Webapi`, `Magento_Integration`, `Magento_Authorization`, `Magento_Backend`, `Magento_Config`.

#### 10. `composer.json` (modify)

Declare `require` on `magento/module-integration`, `magento/module-authorization`, `magento/module-webapi`, `magento/module-config` (any already transitive via sales — declaring them explicitly is cleaner and CI-safe).

#### 11. `etc/webapi.xml` — no change

On `main` all four routes are anonymous (they serve the frontend checkout). On `feat/dedicated-delivery-options-endpoint` the admin route `/V1/myparcel/delivery-options` is already protected by `MyParcelNL_Magento::delivery_options_read` — our integration grants that resource, so the new token unlocks it automatically.

### Day-one ACL resources (locked)

The shipped `integration.xml` requests:

- `Magento_Sales::actions_view` — unlocks `GET /V1/orders`, `/V1/orders/:id`, order comments read, etc.
- `MyParcelNL_Magento::delivery_options_read` — unlocks our own custom endpoint.

Extending later = edit `integration.xml`, bump module version, `setup:upgrade` re-runs `grantPermissions`.

### Branch / sequencing (decided)

This work goes on a brand-new branch off `main`, independent of `feat/dedicated-delivery-options-endpoint`. Our `integration.xml` references `MyParcelNL_Magento::delivery_options_read` even though the ACL resource only exists on the endpoint branch. That is safe: `Magento\Integration\Model\AuthorizationService::grantPermissions()` writes the resource id as a plain string into `authorization_rule`; there is no foreign key to `acl.xml` resources. While the resource doesn't exist on `main`, the grant is a no-op (no route references it). When the endpoint branch merges to `main` the same grant becomes effective immediately — no follow-up `integration.xml` edit needed.

### Admin UX

1. `php -dmemory_limit=-1 bin/magento setup:upgrade` — creates the hidden integration + role + ACL grants.
2. Admin → *Stores → Configuration → MyParcel NL → … → API Access*.
3. Click **Generate**. Token is shown once (copy it). After navigating away the field shows `••••••••`.
4. Admin pastes the token + shop URL into MyParcel backoffice.

The admin will also see "MyParcelNL API" (inactive, auto-provisioned) in *System → Extensions → Integrations* — kept visible (decided) so the granted ACL resources are inspectable. Help-text on our API Access config field plus an explanatory string in the integration's `<endpoint_url>` / description steers the admin not to activate or edit it.

### Client UX (MyParcel backend → customer Magento)

```
curl -H "Authorization: MyParcelNL <token>" \
     "https://shop.example.com/rest/V1/orders?searchCriteria[pageSize]=10"
```

Any REST route whose `<resources>` are in the day-one list returns `200`. Anything else returns `401 Unauthorized` (ACL rejects it before the controller runs).

### What we are deliberately NOT building (and why)

- **Not using native `TokenUserContext` / `Authorization: Bearer`** — gated behind `oauth/consumer/enable_integration_as_bearer`, which the customer would have to flip.
- **Not shipping a frontend to expose the token from the Integrations screen** — that path relies on the same gate and Magento's OAuth activation dance.
- **Not storing a dedicated `myparcel_api_token` table** — one token per store, rotated occasionally; `core_config_data` + encryption is the idiomatic Magento path for this scale.
- **Not implementing token expiry on day one** — rotation is the admin's lever. A TTL can be layered on later via a `created_at` field if MyParcel's backoffice needs it.
- **Not allowing multiple tokens** — one active token per shop keeps the UX trivial. If needed later, switch storage to a table without changing the UserContext shape.

### Critical files (for future maintainers)

| Role | Path | Line |
|------|------|------|
| Bearer-token gate (reason we bypass native) | `vendor/magento/module-integration/Model/OpaqueToken/Reader.php` | 146 |
| Native flag definition | `vendor/magento/module-integration/Model/Config/AuthorizationConfig.php` | 22 |
| Native bearer parser (runs AFTER ours) | `vendor/magento/module-webapi/Model/Authorization/TokenUserContext.php` | 129 |
| Composite context iteration (first wins) | `vendor/magento/module-authorization/Model/CompositeUserContext.php` | 80 |
| ACL enforcement point | `vendor/magento/module-webapi/Controller/Rest/RequestValidator.php` | 104 |
| Role lookup by (userId, userType) | `vendor/magento/module-webapi/Model/WebapiRoleLocator.php` | 43 |
| Integration auto-creation + grantPermissions | `vendor/magento/module-webapi/Model/Plugin/Manager.php` | 96 |
| `integration.xml` XSD | `vendor/magento/module-integration/etc/integration/integration.xsd` | — |
| Registration of native `tokenUserContext` (pattern to mirror) | `vendor/magento/module-webapi/etc/webapi_rest/di.xml` | 14 |
