# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Scope

Only modify code inside this `Magento` directory. If SDK changes (`myparcelnl/sdk`) are needed, explain them to the user rather than making them directly.

## Development Commands

All Magento CLI commands run from the Magento root (`/Applications/MAMP/htdocs/magento246`) with `php -dmemory_limit=-1 bin/magento`.

### After PHP changes
```bash
php -dmemory_limit=-1 bin/magento cache:clean
php -dmemory_limit=-1 bin/magento setup:upgrade
php -dmemory_limit=-1 bin/magento setup:di:compile
```

### After JavaScript changes
```bash
yarn install  # if dependencies changed (run from module directory)
php -dmemory_limit=-1 bin/magento setup:static-content:deploy
php -dmemory_limit=-1 bin/magento cache:clean
```

### After XML configuration changes
```bash
php -dmemory_limit=-1 bin/magento cache:clean config
```

### After database schema changes
```bash
php -dmemory_limit=-1 bin/magento setup:upgrade
```

### Testing
```bash
vendor/bin/pest            # run all tests
vendor/bin/pest --filter=WeightTest  # run a specific test
```
- **Framework:** Pest v1 (on PHPUnit) with Mockery for mocking
- **Config:** `phpunit.xml.dist`, bootstrap in `Tests/bootstrap.php`
- **Tests live in:** `Tests/Unit/` (new location; legacy `Test/Unit/` was removed)
- **CI:** GitHub Actions runs Pest across PHP 8.1–8.4 on push/PR to main/develop (`.github/workflows/test.yml`). PHP 7.4 and 8.0 are not tested (no compatible `magento/framework` resolves on those versions).

## Architecture

**Module:** `MyParcelNL_Magento` — Magento 2 integration for MyParcel shipping (labels, delivery options, multi-carrier support).

**Core dependency:** MyParcel PHP SDK (`myparcelnl/sdk`) handles all API communication. This module is the Magento adapter layer.

### Data Flow
```
Magento Order → Adapter → SDK Consignment → MyParcel API
      ↑                                          ↓
      └──────── Track & Trace Update ────────────┘
```

### Key Components

- **Adapters** (`src/Adapter/`): Convert between Magento and SDK data structures (`DeliveryOptionsFromOrderAdapter`, `OrderLineOptionsFromOrderAdapter`, `ShipmentOptionsFromAdapter`)
- **Carrier** (`src/Model/Carrier/Carrier.php`): Single Magento carrier (`myparcel`) that dispatches to PostNL, DHL variants, DPD, UPS, GLS, Trunkrs
- **Config** (`src/Service/Config.php`): Central configuration access. `Config::carrierPath()` derives a carrier's settings prefix from its name
- **Checkout** (`src/Model/Checkout/DeliveryOptions.php`): Delivery options logic for frontend; frontend JS uses RequireJS + Knockout.js
- **Collections** (`src/Model/Sales/MagentoOrderCollection.php`, `MagentoShipmentCollection.php`): Bridge Magento orders/shipments to SDK for batch API operations
- **Package type** (`src/Service/PackageTypeResolver.php`, `src/Service/CartShippingRules.php`): Package type determination (mailbox, digital stamp, package small) from weight, carrier, capabilities and config, plus what the cart's own products say about how it may ship. Both are stateless; `Tests/Unit/Service/StatelessServicesTest.php` keeps them that way

### Extension Points

- **Observers** (`etc/events.xml`): `sales_order_shipment_save_before` (create concept), `sales_order_invoice_pay` (auto-concept), `sales_model_service_quote_submit_before` (save delivery options)
- **Plugins** (`src/Plugin/`): Order view buttons, shipment email delay until barcode exists, delivery options in REST API responses, custom JSON renderer for versioned responses
- **REST API** (`etc/webapi.xml`):
  - For checkout: `/V1/delivery_options/get`, `/V1/delivery_options/config`, `/V1/shipping_methods`, `/V1/package_type` (anonymous)
  - Admin info: `/V1/myparcel/delivery-options` (ACL-protected, versioned — see REST API Framework below)
- **Virtual types** in `etc/di.xml`: Carrier-specific insurance configurations — follow this pattern when adding carrier features

### REST API Framework (`src/Model/Rest/`)

New versioned REST endpoints follow a structured pattern:

- **AbstractEndpoint**: Base class handling version negotiation via `Content-Type` and `Accept` headers (per [ADR-0011](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md)). Subclasses define `getRequestHandlers()` and `getResourceHandlers()` keyed by version number.
- **VersionContext**: Shared state for negotiated request/response versions, used by response plugins to set correct `Content-Type`.
- **Request handlers** (`Request/`): Parse and transform SDK data into a version-specific array (e.g., `OrderDeliveryOptionsV1Request`).
- **Resource handlers** (`Resource/`): Format the response array, filtering null values (e.g., `OrderDeliveryOptionsV1Resource`).
- **Transformers** (`Transformer/`): Convert individual SDK fields (carrier, date, delivery type, package type, pickup location, shipment options). Named by convention: `{Field}Transformer`.
- **ProblemDetails**: RFC 9457 error responses (`application/problem+json`).
- **Response plugins** (`src/Plugin/Webapi/Rest/Response/`): `VersionContentType` sets versioned content type headers; `ProblemDetailsError` renders errors as RFC 9457.

To add a new versioned endpoint: create an interface in `Api/`, an endpoint class extending `AbstractEndpoint`, request/resource classes per version, and register in `etc/webapi.xml` + `etc/di.xml`.

For endpoints that must be callable with an API access token (3-tier scoped: default / website / store), follow the checklist in [`docs/design/adding-a-token-accessible-rest-endpoint.md`](docs/design/adding-a-token-accessible-rest-endpoint.md). It enumerates all four config files (`etc/webapi.xml`, `etc/acl.xml`, `etc/integration.xml`, `etc/webapi_rest/di.xml`) that always change and when scope-filtering plugins are needed.

### Documentation (`docs/`)

- **ADRs**: Architectural Decision Records live in the engineering-wide [`mypadev/engineering-adr`](https://github.com/mypadev/engineering-adr/tree/main/01-adr) repo, not in this module.
- **SDK v11** ([`docs/sdk-v11.md`](docs/sdk-v11.md)): why the module owns its shipment domain layer, the deliberate divergences from `myparcelnl/pdk`, which vocabulary each boundary takes, the three money scales, and the SDK defects the module works around. Read it before touching `src/Model/Shipment/` or `src/Service/Export/`.
- **Capabilities-driven settings** ([`docs/design/capabilities-driven-settings.md`](docs/design/capabilities-driven-settings.md)): the INT-1289 stack that generates the settings form per scope from account capabilities. PR 4 of 6 replaced `etc/dynamic_settings.json` with `src/Model/Settings/Blueprint/`. Read it before touching `Blueprint/Catalogue.php` or `Model/Shipment/Carrier.php`.
- **FRs** (`docs/functional-requirements/`): Functional requirement specifications
- **TRs** (`docs/technical-requirements/`): Technical requirement specifications
- **OpenAPI** — Core API spec: `https://api.myparcel.nl/openapi.min.json`; Order API spec (enums, ShipmentOptions): `https://order.api.myparcel.nl/openapi.json`
- **Templates** (`docs/templates/`): Document templates for BRs, FRs, TRs, ADRs, and user stories

### Configuration

- Admin settings: generated per scope by `Model\Settings\Blueprint\Generator` from the contract definitions of the scope's API key. Field templates and the module-owned lists live in `Blueprint\Catalogue`. The form's paths at a scope are also the save allow-list in `Observer\ConfigChange`.
- Config paths: `myparcelnl_magento_general/*`, `myparcelnl_magento_[carrier]_settings/*`
  - `config:set` and `config:show` accept a path that the form offers at any scope, through the `PathValidator` plugin `Plugin\Magento\Config\GeneratedSettingsPathValidator`. A CLI save does not run `Observer\ConfigChange`, so its setting validators and the api key import do not run.
- DI: `etc/di.xml` (backend), `etc/frontend/di.xml` (checkout)

  When adding a new admin setting:
  1. Add a `Field` to its group in `Blueprint\Catalogue`, and its label and tooltip to `i18n/`.
     `Tests/Unit/Model/Settings/Blueprint/GeneratorParityTest.php` pins the form against the legacy
     JSON, so name a moved or changed path there.
  2. Give it a value with `withDefault()` if it needs one before a merchant saves it. A switch or a
     fee defaults to `'0'`. `etc/config.xml` holds no MyParcel defaults: `App\Config\Source\GeneratedDefaults`
     supplies them at default scope, below every saved row.
  3. For non-trivial UI (buttons, custom widgets), give the field a `frontend_model`: a block
     class in `src/Block/System/Config/Form/`.
  4. Persist via `Magento\Framework\App\Config\Storage\WriterInterface::save(...)`.
  5. Read scoped existence via `Settings::hasOwnValue($path, $scope, $scopeId)`
     (partition semantics — does NOT cascade).

### Database

Extends `sales_order` with columns: `track_status`, `track_number`, `drop_off_day`, `myparcel_carrier`, `myparcel_export_error`. Schema in `src/Setup/UpgradeSchema.php`.

A shipment option stored in `myparcel_delivery_options` is tri-state: `null` inherits, `true` is on, `false` is off. A checkout write turns the widget's `false` into `null`. See [`docs/design/shipment-options-modal.md`](docs/design/shipment-options-modal.md).

### File Structure Notes

- `Controller/` must be at root level (Magento requirement), not in `src/`
- All other PHP source code lives in `src/`
- Frontend: `view/frontend/` (checkout delivery options via CDN-loaded JS widget)
- Admin: `view/adminhtml/` (label printing, order management)
- Translations: `i18n/` (NL, FR, EN)

## Adding a New Carrier

A carrier that capabilities report needs no code, and the module lists no carrier. Its paths derive from its name through `Config::carrierPath()`, and `Carrier::idFor()` reads its id from the SDK's carrier table, so the settings form, the New Shipment form and the checkout offer it. A carrier the SDK does not know yet renders with its delivery and pickup switches disabled: update the SDK. Do not add a per-carrier exception to `Catalogue`: a fact about a carrier comes from capabilities or from the account's `general_settings`, as the international mailbox flag does.

## Adding a Shipment Option

An option that capabilities offer needs no code. `CapabilitySet::optionsFor()` derives its module name from the wire key (`noTracking` becomes `no_tracking`), the shipment options modal renders it under a label read from that name and stores it on the order, `ShipmentOptionsResolver::resolve()` decides it, and `OrderShipmentOptions` sends it when the SDK has a setter. Combination rules come from the capabilities `requires` and `excludes`, which `resolve()` applies. An option capabilities do not offer for the shipment is left off at export. A merchant's stored `false` beats every default, an 18+ product included.

Add code only for what capabilities cannot say:

1. A label of its own: an entry in `ShipmentOption::LABELS`, and its translation in `i18n/`. Without one, the label is the name, translated through `i18n/`. The settings form gives the option a bare Automate toggle; add it to `Catalogue::FEE_OPTIONS` or `Catalogue::FROM_PRICE_OPTIONS` for a fee or a from-price.
2. A country or delivery type rule: a method on `ShipmentOptionsResolver` and an entry in its `RULES` map.
3. A place in the fail-open form: `ShipmentOption::TO_CHECK` is what the form shows when capabilities could not be read, and the fixed front of the persisted key order in `ShipmentOptions::KEYS`. It needs a constant in `ShipmentOption.php`, and an entry in `V2_NAMES_MAP` only when the name does not derive from the wire key.

The name must match the SDK's snake_case key in `RefShipmentShipmentOptions::setters()`. `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when an option in `TO_CHECK` does not reach the shipment the API receives.

## Dependencies

- PHP 7.4+ or 8.0+ (CI tests run on 8.1–8.4 only; 7.4 and 8.0 are compatible but untested)
- MyParcel SDK v11 (beta)
- Magento Framework 101.0.8+ or 102.0.1+
- Yarn 4.0.1 (frontend)

## Versioning

Semantic release via `release.config.js`. Version is synced across `composer.json`, `package.json`, and `etc/module.xml` by `private/updateVersion.js`.
