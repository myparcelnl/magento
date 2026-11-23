# Contributing

## Adding a shipment option

The name must match the SDK's snake_case key in `RefShipmentShipmentOptions::setters()`. If the SDK
does not know the option, update the SDK first. The key in the request body comes from the SDK's
`ShipmentOptionMapper`; the module keeps no alias.

Which carrier offers the option for which package type comes from capabilities. Add code only for
what capabilities cannot say.

### A row in `ShipmentOption::DEFINITIONS`

Everything the module knows about an option is one row in `ShipmentOption::DEFINITIONS`. Set only
the facts the option needs. The docblocks of `ShipmentOption::FLAGS` and `ShipmentOption::TEXTS` say
what each fact does.

- **A label of its own:** `label`, and its translation in `i18n/`. Without one, the label is the
  name with spaces and a capital (`no_tracking` is "No tracking"), translated through `i18n/`. The shipping method title shows the label when the option is on.
- **A checkout fee:** `checkout`. The rate then adds the fee from `delivery/<name>_fee`.
- **A mailbox option:** `mailbox`. The rate then adds the fee from `mailbox/<name>_fee`.
- **A yes/no option of the shipment:** `toggle`. The New Shipment form shows the toggles when
  capabilities cannot be read; otherwise it shows the options that capabilities give. Each toggle is
  also a key stored on every order.
  `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when a toggle does not reach the
  shipment that the API receives.

The row order is the order of every option list: the REST v1 `shipmentOptions` and the New Shipment
form.

### The New Shipment form

The form shows the `label` of the option's row. Add its translation to every file in `i18n/`.

### An option in the checkout

`checkout` adds only the fee in the rate. The checkout widget must support the option, and
`Model\Quote\Checkout` must pass it on: ask capabilities with `hasOption()`, and add the setting and
its price from `delivery/<name>_fee`, as `signature` does.

### A rule that capabilities do not give

Add a method on `ShipmentOptionsResolver` and an entry in its `RULES` map. `RULES` applies only to an
option with `toggle`. A delivery type rule goes
in `ShipmentOption::LIMIT_DELIVERY_TYPE`.

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
   `getAgeCheckFromProduct()` reads `age_check`. At checkout, read it in `Model\Sales\Repository\PackageRepository`,
   as `priority_delivery` is read.
5. **Test it.** Test the reader with a product that has the attribute on, and with one that has it
   off.

Use `ProductAttributes` to read the attribute, never the EAV tables: Adobe Commerce keys those tables
on `row_id`.
