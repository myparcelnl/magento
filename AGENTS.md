# MyParcel Magento module (MyParcelNL_Magento)

The Magento 2 module for MyParcel shipping: labels, delivery options in the checkout, and several carriers. It is the Magento adapter layer on top of the MyParcel PHP SDK.

## MyParcel stack

This module is not built on the PDK. The other plugins ([myparcelnl/woocommerce](https://github.com/myparcelnl/woocommerce), [myparcelnl/prestashop](https://github.com/myparcelnl/prestashop)) are thin adapters on top of [myparcelnl/pdk](https://github.com/myparcelnl/pdk) and [myparcelnl/js-pdk](https://github.com/myparcelnl/js-pdk). Their rules, and tools such as `pdk-dev`, do not apply here.

What this module shares with the stack:

- [myparcelnl/sdk](https://github.com/myparcelnl/sdk) does all API communication. The names and ids of carriers, delivery types and package types come from the SDK. The module keeps only the name maps (`NAMES_IDS_MAP` and `V2_NAMES_MAP`) in `src/Model/Shipment/` until INT-1563, see `docs/sdk-v11.md`. Do not add other definitions of them.
- The checkout loads the delivery options widget ([myparcelnl/delivery-options](https://github.com/myparcelnl/delivery-options)) from the jsdelivr CDN, in `view/frontend/requirejs-config.js`.

Only change code in this repository. When a task needs an SDK change, explain the change to the user and do not make it.

To find the local checkouts of the other repositories, read `.claude/int-branch.json` in a parent directory. Its `repos` list holds their paths. If there is none and the task needs another repository, tell the user once that the `int-branch` skill of the `external-integrations-basekit` plugin offers to write this file on its first run.

## MyParcel employees: agent plugin

If no skills from the `external-integrations-basekit` plugin are available in this session, tell the user once, at the start, that the plugin is missing and give these commands:

```
/plugin marketplace add mypadev/ai-plugins
/plugin install external-integrations-basekit@myparcel-claude-code-marketplace
```

The marketplace is private, so only MyParcel employees can install it.

## Commands

Run Magento CLI commands from the root of your local Magento install, as `php -dmemory_limit=-1 bin/magento`.

| After a change to | Run |
| --- | --- |
| PHP | `cache:clean`, `setup:upgrade`, `setup:di:compile` |
| JavaScript | `pnpm install` in this module when dependencies changed, then `setup:static-content:deploy`, `cache:clean` |
| XML configuration | `cache:clean config` |
| Database schema | `setup:upgrade` |

## Testing

Run `vendor/bin/pest`, or `vendor/bin/pest --filter=WeightTest` for one test. Tests use Pest with Mockery and live in `Tests/Unit/`.

CI runs Pest on PHP 8.1 to 8.4. PHP 7.4 and 8.0 are in `composer.json` but are not tested: no compatible `magento/framework` resolves on those versions.

## Architecture

```
Magento order -> builder (src/Service/Export/) -> SDK v11 Shipment or Fulfilment Order -> MyParcel API
      ^                                                                                |
      +------------------------------ Track & Trace update -----------------------------+
```

- **Builders** (`src/Service/Export/`) make SDK models from Magento data. Never pass a Magento object to the SDK directly. `src/Adapter/` holds the delivery options that are stored on the order. `src/Model/Shipment/` holds the definitions (carriers, package types, delivery types, options) and value objects; services go in `src/Service/`. The capabilities services are in `src/Service/Capabilities/`, their value objects (`CapabilitySet`, `InsuranceRange`) in `src/Model/Shipment/Capabilities/`.
- **Export** (`Service\Export\ShipmentExportService`) groups shipments per API key, sends them in chunks and makes the SDK calls. The collections (`src/Model/Sales/MagentoOrderCollection.php`, `MagentoShipmentCollection.php`) select the orders and shipments, build them, and pass the result to it. An export goes: controller, then collection, then builder, then export service, then SDK. `Observer\NewShipment` calls the export service itself, and the status cron (`Cron\UpdateStatus`) queries the SDK itself.
- **Carrier** (`src/Model/Carrier/Carrier.php`) is one Magento carrier, `myparcel`, that dispatches to the MyParcel carriers.
- **Config** (`src/Service/Config.php`) holds the config path constants and `CARRIERS_XML_PATH_MAP`. Use the constants, not config paths as strings.
- **Export modes** (`Config::EXPORT_MODE_*`): `shipments` creates shipments; `pps` ("Export entire order") sends whole orders to the Order v1 API. Code and docs also call `pps` "fulfilment" or "Order v1". See `docs/sdk-v11.md`.
- **Insurance** is a range per carrier from the account's contract definitions (`ContractDefinitions::insuranceRangeFor()`, `Model\Shipment\Capabilities\InsuranceRange`). `Service\Settings\Validator\InsuranceAmount` checks each insurance amount setting against it on save.

### REST API (`src/Model/Rest/`)

A new versioned endpoint has an interface in `src/Api/`, an endpoint class that extends `AbstractEndpoint`, one request and one resource class per version, and entries in `etc/webapi.xml` and `etc/di.xml`. Versions come from the `Content-Type` and `Accept` headers, see [ADR-0011](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md). Errors are RFC 9457 `ProblemDetails`.

The response plugins are in `src/Plugin/Magento/Framework/Webapi/Rest/Response/`.

An endpoint that must accept an API access token follows [`docs/design/adding-a-token-accessible-rest-endpoint.md`](docs/design/adding-a-token-accessible-rest-endpoint.md).

### Admin settings

Admin settings are in `etc/dynamic_settings.json`, a temporary solution until the capabilities endpoint replaces it. To add one:

1. Add an entry with `id`, `path`, `type`, `label` and the three `showIn*` flags.
2. For a custom widget, set `frontend_model` to a block in `src/Block/System/Config/Form/`.
3. Save with `Magento\Framework\App\Config\Storage\WriterInterface::save()`.
4. To check whether a scope has its own value, use `Settings::hasOwnValue($path, $scope, $scopeId)`. It does not fall back to the parent scope.

For insurance, a new carrier needs an amount field for each zone it ships to (`insurance_local_amount`, `insurance_belgium_amount`, `insurance_eu_amount`, `insurance_row_amount`) with the `InsuranceAmount` frontend model in `etc/dynamic_settings.json`. The carrier must be in `Config::CARRIERS_XML_PATH_MAP`, or the validator skips its fields. The range comes from the contract definitions, so `etc/di.xml` needs nothing.

### Adding a shipment option

Use the `add-shipment-option` skill. It follows "Adding a shipment option" in
[`CONTRIBUTING.md`](CONTRIBUTING.md), which also covers a toggle on the product.

## Module rules

These rules are specific to this module. A reviewer can not read them from the code.

- `src/Facade/Facade.php` and `src/Facade/Logger.php` use the ObjectManager on purpose, as a static service locator. New code may log through the static `Logger` facade, at any PSR-3 level. Other new code uses constructor injection.
- About twenty older classes still call `ObjectManager::getInstance()`. That is known debt. Do not copy it, and do not report it in a review of a change that does not touch it.
- An around plugin needs a docblock that says why a before or after plugin is not enough. `ProblemDetailsError` is an example: it skips `$proceed()` so that Magento does not render the exception.
- Setup scripts check that a table or column exists before they change it (`isTableExists()`, `tableColumnExists()`). Data migrations go in `src/Setup/Migrations/`.
- Every external host that the frontend loads needs an entry in `etc/csp_whitelist.xml`.
- User-facing strings use `__()`, with a translation in every locale file in `i18n/`. `en_US.csv` is the exception: it holds only the English texts that differ from the source string.

## Documentation

- Requirements are in `docs/` (business requirements, functional requirements, technical requirements, user stories). ADRs are in [mypadev/engineering-adr](https://github.com/mypadev/engineering-adr/tree/main/01-adr), not here.
- [`docs/sdk-v11.md`](docs/sdk-v11.md) says why the module owns its shipment domain layer, where it differs from `myparcelnl/pdk` on purpose, which vocabulary each boundary takes, the three money scales, and which SDK defects it works around. Read it before you change `src/Model/Shipment/` or `src/Service/Export/`.
- Core API spec: `https://api.myparcel.nl/openapi.min.json`. Order API spec, with the enums and `ShipmentOptions`: `https://order.api.myparcel.nl/openapi.json`.

## Versioning

semantic-release sets the version in `composer.json`, `package.json` and `etc/module.xml`, through `private/updateVersion.js`.

Pull requests target `main`. There is no `develop` branch. A merge to `main` does not release: start a release by hand with the "Release" workflow (`.github/workflows/release.yml`).
