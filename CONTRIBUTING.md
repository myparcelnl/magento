# Contributing

## Adding a shipment option

The name must match the SDK's snake_case key in `RefShipmentShipmentOptions::setters()`. If the SDK
does not know the option, update the SDK first. The wire key comes from the SDK's
`ShipmentOptionMapper`; the module keeps no alias.

An option that capabilities offer needs no code. `CapabilitySet::optionsFor()` derives its module
name from the wire key (`noTracking` becomes `no_tracking`). The shipment options modal renders it
under a label read from that name and stores it on the order. `ShipmentOptionsResolver::resolve()`
decides it, and `OrderShipmentOptions` sends it when the SDK has a setter. Combination rules come from
the capabilities `requires` and `excludes`, which `resolve()` applies. At export, the module leaves
off an option that capabilities do not offer for the shipment.

Add code only for what capabilities cannot say.

### A row in `ShipmentOption::DEFINITIONS`

Everything the module knows about an option is one row in `ShipmentOption::DEFINITIONS`. Set only
the facts the option needs. The docblocks of `ShipmentOption::FLAGS` and `ShipmentOption::TEXTS` say
what each fact does.

- **A label of its own:** `label`, and its translation in `i18n/`. Without one, the label is the
  name, translated through `i18n/`. The shipping method title shows the label when the option is on.
  `settingsLabel` and `tooltip` give the settings form its own label and tooltip.
- **A fee or a from-price:** `checkout` for an option with a checkout fee, `fromPrice` (and
  `fromPriceTooltip`) for one that the settings automate above an order total.
- **A mailbox option:** `mailbox`. Its settings then go in the mailbox group, not in the default
  options group.
- **A place in the fail-open form:** `toggle`. The New Shipment form shows these options when
  capabilities could not be read. Each toggle is also a key stored on every order.
  `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when a toggle does not reach the
  shipment that the API receives.

The row order is the order of the settings form.

### An option in the checkout

`checkout` adds only the settings fields and the fee in the rate. The checkout widget must support the option, and
`Model\Quote\Checkout` must pass it on: ask capabilities with `hasOption()`, and add the setting and
its price from `delivery/<name>_fee`, as `signature` does.

### A rule that capabilities do not give

Add a method on `ShipmentOptionsResolver` and an entry in its `RULES` map. A delivery type rule goes
in `ShipmentOption::LIMIT_DELIVERY_TYPE`. Do not add a `RULES` entry for a combination rule: the
capabilities `requires` and `excludes` give those.

### A toggle on the product

A product toggle is not generic: each one is a Magento EAV attribute with its own reader. Do these
steps in this order:

1. **Install the attribute.** Add a step in `Setup\UpgradeData::upgrade()`, gated with
   `version_compare($context->getVersion(), '<next release>', '<')`, that calls
   `$eavSetup->addAttribute()` for `myparcel_<name>`. Do not change an older step: an installed shop
   does not run it again. Do not change `setup_version` by hand; the release does that.
2. **Translate it.** Add the label and the note to every file in `i18n/`.
3. **Name it for the readers.** Add the name without the `myparcel_` prefix to
   `Service\ProductAttributes::ATTRIBUTES`, so that one load reads it with the others.
4. **Read it where it applies.** For the shipment, read it in `ShipmentOptionsResolver`, as
   `hasAgeCheck()` reads `age_check`. At checkout, read it in `Service\CartShippingRules`, as
   `priority_delivery` is read.
5. **Test it.** Test the reader with a product that has the attribute on, and with one that has it
   off.

Use `ProductAttributes` to read the attribute, never the EAV tables: Adobe Commerce keys those tables
on `row_id`.
