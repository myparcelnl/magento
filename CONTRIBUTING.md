# Contributing

## Adding a shipment option

An option that capabilities offer needs no code. `CapabilitySet::optionsFor()` derives its module
name from the wire key (`noTracking` becomes `no_tracking`). The shipment options modal renders it
under a label read from that name and stores it on the order. `ShipmentOptionsResolver::resolve()`
decides it, and `OrderExportOptions` sends it when the SDK has a setter. Combination rules come from
the capabilities `requires` and `excludes`, which `resolve()` applies. At export, the module leaves
off an option that capabilities do not offer for the shipment.

A `toggle` option needs its snake_case key in `RefShipmentShipmentOptions::setters()`:
`OrderExportOptions` throws a `LogicException` without one. An option with only a label, such as
`printerless_return`, needs a key that the SDK's `ShipmentOptionMapper` knows. If the SDK does not
know the option, update the SDK first. The key in the request body comes from `ShipmentOptionMapper`;
the module keeps no alias.

Add code only for what capabilities cannot say.

### A row in `ShipmentOption::DEFINITIONS`

Everything else the module knows about an option is one row in `ShipmentOption::DEFINITIONS`. Set
only the facts the option needs. The docblocks of `ShipmentOption::FLAGS` and `ShipmentOption::TEXTS`
say what each fact does.

- **A label of its own:** `label`, and its translation in `i18n/`. Without one, the label is the
  name with spaces and a capital (`no_tracking` is "No tracking"), translated through `i18n/`. `settingsLabel` and `tooltip` give the settings form its own
  label and tooltip.
- **A fee or a from-price:** `checkout` for an option with a checkout fee, `fromPrice` (and
  `fromPriceTooltip`) for one that the settings automate above an order total. The rate adds the fee
  from `delivery/<name>_fee`, and the checkout widget shows it as `price<Name>` (`only_recipient` is
  `priceOnlyRecipient`).
- **A mailbox option:** `mailbox`. Its settings then go in the mailbox group, not in the default
  options group, and its fee is `mailbox/<name>_fee`.
- **A place in the fail-open form:** `toggle`. Each toggle is also a key stored on every order.
  `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when a toggle does not reach the
  shipment that the API receives.
- **An option that an order must keep:** `keepAtCheckout`, as for the age check of an 18+ order. The
  checkout then sends the option in its capabilities lookup, and does not offer a carrier, package
  type or delivery type that cannot carry it.

The row order is the order of every option list: the REST v1 `shipmentOptions` and the New Shipment
form.

### An option in the checkout

`checkout` adds the settings fields, the fee in the rate and the price in the widget. The checkout
widget must support the option, and `Model\Quote\Checkout` must switch it on:

1. In `carrierSettings`, add `allow<Name>`, from `hasOption()` and `delivery/<name>_active`, as
   `signature` does.
2. Add a `<name>_title` row to `Catalogue::TITLES` with the fact `option:<name>`, and pass the title
   to the widget with the other titles.

### A rule that capabilities do not give

Read the rule in `Model\Source\DefaultOptions::sourceOf()`, as for a product toggle. The New
Shipment form and the export both read `DefaultOptions`, so they agree. A rule in
`ShipmentOptionsResolver` is not enough: only the export calls `resolve()`. A delivery type rule needs
no code, because the capabilities lookup sends the delivery type.

### A toggle on the product

A product toggle is not generic: each one is a Magento EAV attribute with its own reader. Do these
steps in this order:

1. **Install the attribute.** Add a step in `Setup\UpgradeData::upgrade()`, gated with
   `version_compare($context->getVersion(), '<next release>', '<')`. Call
   `$eavSetup->addAttribute(Product::ENTITY, 'myparcel_<name>', …)` with `DEFAULT_ATTRIBUTES`, a
   `label`, a `note` and `'input' => 'boolean'`. Do not change an older step: an installed shop does
   not run it again. Do not change `setup_version` by hand; the release does that.
2. **Translate it.** Add the `label` and the `note` to every locale file in `i18n/` except `en_US.csv`.
3. **Name it for the readers.** Add the name without the `myparcel_` prefix to
   `Service\ProductAttributes::ATTRIBUTES`, so that one load reads it with the others.
4. **Read it where it applies:**
   - For the shipment, the attribute sets the order's default for the option: read it in
     `Model\Source\DefaultOptions::sourceOf()` and return `OptionSource::PRODUCT`, as `age_check`
     does. `age_check` is a select with three values, not a boolean; read a boolean attribute as
     `CartShippingRules` reads `priority_delivery`. The New Shipment form and the export both read `DefaultOptions`, so they show the same
     default, and the exclusion ranking sees the product as the source. A rule on
     `ShipmentOptionsResolver` is not enough: the form does not read it.
   - At checkout, the attribute decides what the consumer may choose: read it in
     `Service\CartShippingRules`. `priority_delivery` is the example.
5. **Test it.** Test the reader with a product that has the attribute on, and with one that has it
   off. `Tests/Helpers/DeliveryOptionsFixtures.php` takes every toggle from `ShipmentOption::DEFINITIONS`.

Use `ProductAttributes` to read the attribute, never the EAV tables: Adobe Commerce keys those tables
on `row_id`.
