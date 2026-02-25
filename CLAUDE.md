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
No test framework is currently set up. Existing unit tests are in `Test/Unit/`.

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
- **Config** (`src/Service/Config.php`): Central configuration access with `CARRIERS_XML_PATH_MAP` for carrier-specific settings
- **Checkout** (`src/Model/Checkout/DeliveryOptions.php`): Delivery options logic for frontend; frontend JS uses RequireJS + Knockout.js
- **Collections** (`src/Model/Sales/MagentoOrderCollection.php`, `MagentoShipmentCollection.php`): Bridge Magento orders/shipments to SDK for batch API operations
- **Package** (`src/Model/Sales/Package.php`): Complex package type determination (mailbox, digital stamp, package) based on weight, carrier, and config

### Extension Points

- **Observers** (`etc/events.xml`): `sales_order_shipment_save_before` (create concept), `sales_order_invoice_pay` (auto-concept), `sales_model_service_quote_submit_before` (save delivery options)
- **Plugins** (`src/Plugin/`): Order view buttons, shipment email delay until barcode exists, delivery options in REST API responses
- **REST API** (`etc/webapi.xml`): `/V1/delivery_options/get`, `/V1/delivery_options/config`, `/V1/shipping_methods`, `/V1/package_type`
- **Virtual types** in `etc/di.xml`: Carrier-specific insurance configurations — follow this pattern when adding carrier features

### Configuration

- Admin settings: `etc/dynamic_settings.json` (intermediate solution, will be replaced by capabilities endpoint later)
- Config paths: `myparcelnl_magento_general/*`, `myparcelnl_magento_[carrier]_settings/*`
- DI: `etc/di.xml` (backend), `etc/frontend/di.xml` (checkout)

### Database

Extends `sales_order` with columns: `track_status`, `track_number`, `drop_off_day`, `myparcel_carrier`. Schema in `src/Setup/UpgradeSchema.php`.

### File Structure Notes

- `Controller/` must be at root level (Magento requirement), not in `src/`
- All other PHP source code lives in `src/`
- Frontend: `view/frontend/` (checkout delivery options via CDN-loaded JS widget)
- Admin: `view/adminhtml/` (label printing, order management)
- Translations: `i18n/` (NL, FR, EN)

## Adding a New Carrier

1. Add admin settings and defaults in `etc/dynamic_settings.json`
2. Add virtual types for insurance in `etc/di.xml`
3Update carrier detection in relevant services

## Dependencies

- PHP 7.4+ or 8.0+
- MyParcel SDK v10.4+
- Magento Framework 101.0.8+ or 102.0.1+
- Yarn 4.0.1 (frontend)

## Versioning

Semantic release via `release.config.js`. Version is synced across `composer.json`, `package.json`, and `etc/module.xml` by `private/updateVersion.js`.
