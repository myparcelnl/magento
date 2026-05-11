# Adding a token-accessible REST endpoint with 3-tier scoping

Developer checklist for adding a new REST endpoint that can be called with an API access token (default / website / store scoped).

## Context

Authentication, scope binding, and the deny-by-default allow-list are centralized. Adding a new endpoint is mostly static config — the gating logic does not change per endpoint.

For any token-authenticated request, **three gates must all pass**:

1. **Integration grant** — `etc/integration.xml` grants the ACL resource to the `MyParcel API` integration.
2. **Token allow-list** — `ScopedResourceRegistry` (configured in `etc/webapi_rest/di.xml`) lists the resource as token-callable.
3. **Per-row scope** — `TokenScopeContext` constrains returned data to the token's permitted stores.

Steps 1 + 2 are always required; step 3 depends on what the endpoint queries.

## Files to touch

### 1. Service contract — `src/Api/<Name>Interface.php`

- New interface declaring the method(s) the endpoint exposes.
- Annotate parameters and return types for webapi serialization.

### 2. Implementation — `src/Model/Rest/<Name>.php`

- For versioned content negotiation (Accept / Content-Type → `application/vnd.myparcel.v1+json`), extend `AbstractEndpoint` and define `getRequestHandlers()` / `getResourceHandlers()` per version. Canonical example: `src/Model/Rest/OrderDeliveryOptions.php`.
- Add per-version request and resource classes under `src/Model/Rest/Request/` and `src/Model/Rest/Resource/`.
- For non-versioned endpoints, implement the interface directly.

### 3. Route — `etc/webapi.xml`

```xml
<route url="/V1/myparcel/<path>" method="GET">
    <service class="MyParcelNL\Magento\Api\<Name>Interface" method="<method>"/>
    <resources>
        <resource ref="MyParcelNL_Magento::<feature>_read"/>
    </resources>
</route>
```

### 4. ACL resource — `etc/acl.xml`

Add `<resource id="MyParcelNL_Magento::<feature>_read" title="..." sortOrder="..."/>` under the existing `Magento_Backend::admin` → `stores` → `config` tree. Mirror the existing `delivery_options_read` entry.

### 5. Integration grant — `etc/integration.xml`

Add `<resource name="MyParcelNL_Magento::<feature>_read"/>` to the `MyParcel API` integration. Without this, even a valid token has no admin permission for the resource.

### 6. Token allow-list — `etc/webapi_rest/di.xml`

Add an entry to the `$resources` argument of `ScopedResourceRegistry`:

```xml
<item name="<feature>_read" xsi:type="string">MyParcelNL_Magento::<feature>_read</item>
```

**This is the line that actually opens the endpoint to tokens.** Skipping it makes `MyParcelTokenAclGate` deny the request even with the integration grant in place.

### 7. Scope filtering — only if needed

- **If the endpoint reads orders via `OrderRepositoryInterface`**: nothing to do. `OrderRepositoryStoreFilter` (`src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php`) handles both `getList()` (injects `store_id IN (...)`) and `get()` (throws `NoSuchEntityException` if out of scope).
- **For any other data source** (other repositories, direct DB, config reads):
  - Inject `MyParcelNL\Magento\Model\Authorization\TokenScopeContext` into the handler.
  - Constrain queries with `permittedStoreIds(): ?int[]` (null = no token / no constraint).
  - Or gate per-record with `assertStoreInScope(int $storeId): void`.
  - For Magento repository-backed sources, mirror `OrderRepositoryStoreFilter` as a `before/after` plugin in `etc/webapi_rest/di.xml`.

## Tests

- Unit-test the handler with `TokenScopeContext` for default / website / store owners. Reference pattern: `Tests/Unit/Model/Authorization/TokenScopeContextTest.php` (see `fourStoreFixture`).
- Cover the three denial paths:
  - Token absent → session / admin path still works.
  - Token present but resource missing from allow-list → 401 / 403 via `MyParcelTokenAclGate`.
  - Token present, resource allowed, but record outside `permittedStoreIds()` → 404 (`NoSuchEntityException`).

## Verification

```bash
php -dmemory_limit=-1 bin/magento cache:clean
php -dmemory_limit=-1 bin/magento setup:upgrade
php -dmemory_limit=-1 bin/magento setup:di:compile
vendor/bin/pest
```

Manual smoke (replace `<token>` with a token from `core_config_data` for the expected scope row):

```bash
curl -H "Authorization: myparcel <token>" \
     -H "Accept: application/json" \
     https://<store>.acceptance.myparcel.nl/rest/V1/myparcel/<path>
```

Verify:
- 200 + scoped payload for a record inside the token's scope.
- 404 for a record outside.
- 401 / 403 with the resource removed from `ScopedResourceRegistry`.

## Key reference files

- `src/Model/Authorization/ApiAccessTokenUserContext.php` — token → owner row resolution.
- `src/Model/Authorization/TokenScopeContext.php` — scope state, `permittedStoreIds()`, `assertStoreInScope()`.
- `src/Plugin/Magento/Webapi/Rest/RequestValidator/MyParcelTokenAclGate.php` — allow-list gate.
- `src/Service/ScopedResourceRegistry.php` — registry storage.
- `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` — reference pattern for store filtering.
- `src/Model/Rest/OrderDeliveryOptions.php` + `src/Model/Rest/AbstractEndpoint.php` — versioned endpoint reference.
- `etc/webapi.xml`, `etc/acl.xml`, `etc/integration.xml`, `etc/webapi_rest/di.xml` — the four config files that always change.

## Related

- TR-000004 (`docs/technical-requirements/TR-000004-rest-api-access-token-authentication.md`) — full requirement specification for token authentication and scoping.
