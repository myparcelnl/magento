# MyParcel Magento module (MyParcelNL_Magento)

The Magento 2 module for MyParcel shipping: labels, delivery options in the checkout, and several carriers. It is the Magento adapter layer on top of the MyParcel PHP SDK.

## MyParcel stack

This module is not built on the PDK. The other plugins ([myparcelnl/woocommerce](https://github.com/myparcelnl/woocommerce), [myparcelnl/prestashop](https://github.com/myparcelnl/prestashop)) are thin adapters on top of [myparcelnl/pdk](https://github.com/myparcelnl/pdk) and [myparcelnl/js-pdk](https://github.com/myparcelnl/js-pdk). Their rules, and tools such as `pdk-dev-on`, do not apply here.

What this module shares with the stack:

- [myparcelnl/sdk](https://github.com/myparcelnl/sdk) does all API communication. Carriers, delivery types and package types come from the SDK. Do not add definitions of them to this module.
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
| JavaScript | `yarn install` in this module when dependencies changed, then `setup:static-content:deploy`, `cache:clean` |
| XML configuration | `cache:clean config` |
| Database schema | `setup:upgrade` |

## Testing

Run `vendor/bin/pest`, or `vendor/bin/pest --filter=WeightTest` for one test. Tests use Pest with Mockery and live in `Tests/Unit/`.

CI runs Pest on PHP 8.1 to 8.4. PHP 7.4 and 8.0 are in `composer.json` but are not tested: no compatible `magento/framework` resolves on those versions.

## Architecture

```
Magento order -> Adapter -> SDK consignment -> MyParcel API
      ^                                            |
      +----------- Track & Trace update -----------+
```

- **Adapters** (`src/Adapter/`) convert between Magento and SDK data. Never pass a Magento object to the SDK directly.
- **Collections** (`src/Model/Sales/MagentoOrderCollection.php`, `MagentoShipmentCollection.php`) send orders and shipments to the SDK in batches. A new SDK call goes through them: controller or observer, then collection, then adapter, then SDK.
- **Carrier** (`src/Model/Carrier/Carrier.php`) is one Magento carrier, `myparcel`, that dispatches to the MyParcel carriers.
- **Config** (`src/Service/Config.php`) holds the config path constants. `Config::carrierPath()` derives a carrier's settings prefix from its name. Use the constants, not config paths as strings.
- **Export modes** (`Config::EXPORT_MODE_*`): `shipments` creates shipments; `pps` ("Export entire order") sends whole orders to the Order v1 API. Code and docs also call `pps` "fulfilment" or "Order v1". See `docs/sdk-v11.md`.
- **Package type** (`src/Service/PackageTypeResolver.php`, `src/Service/CartShippingRules.php`) decides the package type (mailbox, digital stamp, package small) from weight, carrier, capabilities and config, and from what the products in the cart allow. Both are stateless; `Tests/Unit/Service/StatelessServicesTest.php` keeps them that way.
- **Insurance** is a range per carrier from the account's capabilities (`Model\Shipment\Capabilities\InsuranceRange`). `Model\Settings\Validator\InsuranceAmount` checks each insurance amount setting against it on save.

### REST API (`src/Model/Rest/`)

A new versioned endpoint has an interface in `src/Api/`, an endpoint class that extends `AbstractEndpoint`, one request and one resource class per version, and entries in `etc/webapi.xml` and `etc/di.xml`. Versions come from the `Content-Type` and `Accept` headers, see [ADR-0011](https://github.com/mypadev/engineering-adr/blob/main/01-adr/0011-api-versioning-via-headers.md). Errors are RFC 9457 `ProblemDetails`.

The response plugins are in `src/Plugin/Magento/Framework/Webapi/Rest/Response/`.

An endpoint that must accept an API access token follows [`docs/design/adding-a-token-accessible-rest-endpoint.md`](docs/design/adding-a-token-accessible-rest-endpoint.md).

### Admin settings

`Model\Settings\Blueprint\Generator` generates the admin settings per scope from the contract definitions of the scope's API key. Field templates and the module-owned lists are in `Blueprint\Catalogue`. The form's paths at a scope are also the save allow-list in `Observer\ConfigChange`.

`config:set` and `config:show` accept a path that the form offers at any scope, through the `PathValidator` plugin `Plugin\Magento\Config\GeneratedSettingsPathValidator`. A CLI save does not run `Observer\ConfigChange`, so its setting validators and the api key import do not run.

To add a setting:

1. Add a `Field` to its group in `Blueprint\Catalogue`, and its label and tooltip to `i18n/`. `Tests/Unit/Model/Settings/Blueprint/GeneratorParityTest.php` pins the form against the legacy JSON, so name a moved or changed path there.
2. Give it a value with `withDefault()` if it needs one before a merchant saves it. A switch or a fee defaults to `'0'`. `etc/config.xml` holds no MyParcel defaults: `App\Config\Source\GeneratedDefaults` supplies them at default scope, below every saved row.
3. For a custom widget, give the field a `frontend_model`: a block in `src/Block/System/Config/Form/`.
4. Save with `Magento\Framework\App\Config\Storage\WriterInterface::save()`.
5. To check whether a scope has its own value, use `Settings::hasOwnValue($path, $scope, $scopeId)`. It does not fall back to the parent scope.

### Adding a carrier

A carrier that capabilities report needs no code, and the module lists no carrier. Its paths derive from its name through `Config::carrierPath()`, and `Carrier::idFor()` reads its id from the SDK's carrier table, so the settings form, the New Shipment form and the checkout offer it. A carrier the SDK does not know yet renders with its delivery and pickup switches disabled: update the SDK. Do not add a per-carrier exception to `Catalogue`: a fact about a carrier comes from capabilities or from the account's `general_settings`, as the international mailbox flag does.

### Adding a shipment option

Use the `add-shipment-option` skill. It follows "Adding a shipment option" in
[`CONTRIBUTING.md`](CONTRIBUTING.md), which also covers a toggle on the product.

## Module rules

These rules are specific to this module. A reviewer can not read them from the code.

- `src/Facade/Facade.php` and `src/Facade/Logger.php` use the ObjectManager on purpose, as a static service locator. New code may call `Logger::notice()` and `Logger::error()`. Other new code uses constructor injection.
- About twenty older classes still call `ObjectManager::getInstance()`. That is known debt. Do not copy it, and do not report it in a review of a change that does not touch it.
- An around plugin needs a docblock that says why a before or after plugin is not enough. `ProblemDetailsError` is an example: it skips `$proceed()` so that Magento does not render the exception.
- Setup scripts check that a table or column exists before they change it (`isTableExists()`, `tableColumnExists()`). Data migrations go in `src/Setup/Migrations/`.
- Every external host that the frontend loads needs an entry in `etc/csp_whitelist.xml`.
- User-facing strings use `__()`. The translations are in `i18n/` (NL, FR, EN).

## Documentation

- Requirements are in `docs/` (business requirements, functional requirements, technical requirements, user stories). ADRs are in [mypadev/engineering-adr](https://github.com/mypadev/engineering-adr/tree/main/01-adr), not here.
- [`docs/sdk-v11.md`](docs/sdk-v11.md) says why the module owns its shipment domain layer, where it differs from `myparcelnl/pdk` on purpose, which vocabulary each boundary takes, the three money scales, and which SDK defects it works around. Read it before you change `src/Model/Shipment/` or `src/Service/Export/`.
- [`docs/design/capabilities-driven-settings.md`](docs/design/capabilities-driven-settings.md) describes the INT-1289 stack that generates the settings form per scope from account capabilities. PR 4 of 6 replaced `etc/dynamic_settings.json` with `src/Model/Settings/Blueprint/`. Read it before you change `Blueprint/Catalogue.php` or `Model/Shipment/Carrier.php`.
- Core API spec: `https://api.myparcel.nl/openapi.min.json`. Order API spec, with the enums and `ShipmentOptions`: `https://order.api.myparcel.nl/openapi.json`.

## Versioning

semantic-release sets the version in `composer.json`, `package.json` and `etc/module.xml`, through `private/updateVersion.js`.

Pull requests target `main`. There is no `develop` branch. A merge to `main` does not release: start a release by hand with the "Release" workflow (`.github/workflows/release.yml`).
