# Adding a token-accessible REST endpoint with 3-tier scoping

Developer checklist for adding a new REST endpoint that can be called with an API access token (default / website / store scoped).

## Context

Authentication, scope binding, and the deny-by-default allow-list are centralized. Adding a new endpoint is mostly static config — the gating logic does not change per endpoint.

For any token-authenticated request, **three gates must all pass**:

1. **Integration grant** — `etc/integration.xml` grants the ACL resource to the `MyParcel API` integration.
2. **Token allow-list** — `ScopedResourceRegistry` (configured in `etc/webapi_rest/di.xml`) lists the resource as token-callable.
3. **Per-row scope** — `TokenScopeContext` constrains returned data to the token's permitted stores.

Steps 1 + 2 are always required; step 3 depends on what the endpoint queries.

## Access matrix (current state)

This is the complete set of access an API access token can have today.

All scopes below support default / website / store.

| ACL resource | Routes it authorizes | Filter (gate 3) |
|---|---|---|
| `MyParcelNL_Magento::delivery_options_read` | `GET /V1/myparcel/delivery-options` | `OrderRepositoryStoreFilter` (the endpoint loads the order via `OrderRepositoryInterface`) |
| `Magento_Sales::actions_view` | `GET /V1/orders`, `/V1/orders/:id` | `OrderRepositoryStoreFilter` |
| | `GET /V1/orders/items`, `/V1/orders/items/:id` | `OrderItemRepositoryStoreFilter` |
| | `GET /V1/orders/:id/comments`, `/V1/orders/:id/statuses` | `OrderManagementStoreFilter` |

`Magento_Sales::actions_view` is granted **because the MyParcel backoffice needs those native
`/V1/orders*` reads** (`TR-000004` §Rationale).

Sources of truth (must stay in sync; the regression test `Tests/Unit/Service/ScopedResourceRegistryTest.php` enforces alignment between the first two):

- `etc/integration.xml` — gate 1.
- `etc/webapi_rest/di.xml` `ScopedResourceRegistry` — gate 2.
- `etc/webapi.xml` — which route requires which resource.
- `etc/acl.xml` — where the resource sits in the admin tree.

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

Also update the **Access matrix** at the top of this document with the new resource → route → filter row.

### 7. Scope filtering — only if needed

First **enumerate every route the resource authorizes**, not just the one you are adding:

```bash
grep -rn -B12 'resource ref="<the resource>"' vendor/magento/module-*/etc/webapi.xml \
  | grep -E 'route url|service class'
```

Then, for each service in that list:

- **Reads orders via `OrderRepositoryInterface`**: nothing to do. `OrderRepositoryStoreFilter` (`src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php`) handles both `getList()` and `get()`.
- **Reads order items or order comments/statuses**: nothing to do. `OrderItemRepositoryStoreFilter` and `OrderManagementStoreFilter` cover those.
- **For any other data source** (other repositories, direct DB, config reads):
  - For `getList`-shaped paths, inject `MyParcelNL\Magento\Service\Authorization\StoreScopeSearchCriteria` and call `apply($searchCriteria)` — it already handles the null (non-token) and empty-permitted-set cases, so do not re-implement the `store_id IN (...)` construction.
  - For `get(id)`-shaped paths, inject `TokenScopeContext` and either compare against `permittedStoreIds(): ?int[]` (null = no token / no constraint) or call `assertStoreInScope(int $storeId): void`. Throw `NoSuchEntityException` (404), never a 403 — a 403 confirms the record exists.
  - If the record carries no trustworthy `store_id` of its own, resolve it through the already-filtered `OrderRepositoryInterface` rather than duplicating the boundary check; `OrderManagementStoreFilter` is the reference for that.
  - Register the plugin in `etc/webapi_rest/di.xml` (that area only — the admin must stay unaffected).

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
- `src/Service/Authorization/StoreScopeSearchCriteria.php` — the shared `store_id IN (...)` injector.
- `src/Plugin/Magento/Sales/OrderRepositoryStoreFilter.php` — store filtering over `getList` + `get`.
- `src/Plugin/Magento/Sales/OrderItemRepositoryStoreFilter.php` — scope from the record's own `store_id`.
- `src/Plugin/Magento/Sales/OrderManagementStoreFilter.php` — gating a service that takes only an order id.
- `src/Model/Rest/OrderDeliveryOptions.php` + `src/Model/Rest/AbstractEndpoint.php` — versioned endpoint reference.
- `etc/webapi.xml`, `etc/acl.xml`, `etc/integration.xml`, `etc/webapi_rest/di.xml` — the four config files that always change.

## Related

- TR-000004 (`docs/technical-requirements/TR-000004-rest-api-access-token-authentication.md`) — full requirement specification for token authentication and scoping.
