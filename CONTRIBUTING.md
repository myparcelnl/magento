# Contributing

## Adding a shipment option

A `toggle` option needs its snake_case key in `RefShipmentShipmentOptions::setters()`:
`OrderShipmentOptions` throws a `LogicException` without one. An option with only a label, such as
`printerless_return`, needs a key that the SDK's `ShipmentOptionMapper` knows. If the SDK does not
know the option, update the SDK first. The key in the request body comes from `ShipmentOptionMapper`;
the module keeps no alias.

An option that capabilities offer needs no code. `CapabilitySet::optionsFor()` derives its module
name from the wire key (`noTracking` becomes `no_tracking`). The New Shipment form renders it under a
label read from that name, and `MagentoCollection` reads its checkbox through
`mypa_rendered_options[]`. `ShipmentOptionsResolver::resolve()` decides it, and
`OrderShipmentOptions` sends it when the SDK has a setter. Combination rules come from the
capabilities `requires` and `excludes`, which `resolve()` applies.

Add code only for what capabilities cannot say.

### A row in `ShipmentOption::DEFINITIONS`

Everything the module knows about an option is one row in `ShipmentOption::DEFINITIONS`. Set only
the facts the option needs. The docblocks of `ShipmentOption::FLAGS` and `ShipmentOption::TEXTS` say
what each fact does.

- **A label of its own:** `label`, and its translation in `i18n/`. Without one, the label is the
  name with spaces and a capital (`no_tracking` is "No tracking"), translated through `i18n/`. The shipping method title shows the label when the option is on.
- **A checkout fee:** `checkout`. The rate adds the fee from `delivery/<name>_fee`, and the checkout
  widget shows it as `price<Name>` (`only_recipient` is `priceOnlyRecipient`).
- **A mailbox option:** `mailbox`. The same, with the fee from `mailbox/<name>_fee`. Add that
  setting to `etc/dynamic_settings.json`.
- **A place in the fail-open form:** `toggle`. The New Shipment form shows these options when
  capabilities could not be read. Each toggle is also a key stored on every order.
  `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when a toggle does not reach the
  shipment that the API receives.

The row order is the order of every option list: the REST v1 `shipmentOptions` and the New Shipment
form.

### An option in the checkout

`checkout` gives the fee to the rate and to the widget. The checkout widget must support the option,
and `Model\Quote\Checkout` must switch it on: add its `allow<Name>` key, from `hasOption()` and
`delivery/<name>_active`. Add `delivery/<name>_active` and `delivery/<name>_fee` to
`etc/dynamic_settings.json` for each carrier that offers the option, as `signature` does.

### A rule that capabilities do not give

Add a method on `ShipmentOptionsResolver` and an entry in its `RULES` map. `RULES` applies only to an option with `toggle`. A delivery type rule goes
in `ShipmentOption::LIMIT_DELIVERY_TYPE`. Do not add a `RULES` entry for a combination rule: the
capabilities `requires` and `excludes` give those.

### A toggle on the product

A product toggle is not generic: each one is a Magento EAV attribute with its own reader. Do these
steps in this order:

1. **Install the attribute.** Add a step in `Setup\UpgradeData::upgrade()`, gated with
   `version_compare($context->getVersion(), '<next release>', '<')`, that calls
   `$eavSetup->addAttribute()` for `myparcel_<name>`. Do not change an older step: an installed shop
   does not run it again. Do not change `setup_version` by hand; the release does that.
2. **Translate it.** Add the label and the note to every locale file in `i18n/` except `en_US.csv`.
3. **Name it for the readers.** Add the name without the `myparcel_` prefix to
   `Service\ProductAttributes::ATTRIBUTES`, so that one load reads it with the others.
4. **Read it where it applies.** For the shipment, read it in `ShipmentOptionsResolver`, as
   `getAgeCheckFromProduct()` reads `age_check`. At checkout, read it in `Service\CartShippingRules`, as
   `priority_delivery` is read.
5. **Test it.** Test the reader with a product that has the attribute on, and with one that has it
   off.

Use `ProductAttributes` to read the attribute, never the EAV tables: Adobe Commerce keys those tables
on `row_id`.
