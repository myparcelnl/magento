# Contributing

## Adding a shipment option

An option that capabilities offer needs no code. `CapabilitySet::optionsFor()` derives its module
name from the wire key (`noTracking` becomes `no_tracking`). The shipment options modal renders it
under a label read from that name and stores it on the order. `ShipmentOptionsResolver::resolve()`
decides it, and `OrderExportOptions` sends it when the SDK has a setter. Combination rules come from
the capabilities `requires` and `excludes`, which `resolve()` applies. At export, the module leaves
off an option that capabilities do not offer for the shipment.

The name must match the SDK's snake_case key in `RefShipmentShipmentOptions::setters()`. If the SDK
does not know the option, update the SDK first.

Add code only for what capabilities cannot say.

### A row in `ShipmentOption::DEFINITIONS`

Everything else the module knows about an option is one row in `ShipmentOption::DEFINITIONS`. Set
only the facts the option needs; the docblock of the table says what each fact does.

- **A label of its own:** `label`, and its translation in `i18n/`. Without one, the label is the
  name, translated through `i18n/`.
- **A fee or a from-price:** `checkout` for an option that the checkout renders with a fee,
  `fromPrice` for one that the settings automate above an order total.
- **A place in the fail-open form:** `toggle`. Each toggle is also a key stored on every order.
  `Tests/Unit/Model/Shipment/ShipmentOptionParityTest.php` fails when a toggle does not reach the
  shipment that the API receives.
- **An option that an order must keep:** `keepAtCheckout`, as for the age check of an 18+ order. The
  checkout then sends the option in its capabilities lookup, and does not offer a carrier, package
  type or delivery type that cannot carry it.
- **A wire key that does not derive from the name:** `v2Name`.

The row order is the order of the settings form.

### A rule that capabilities do not give

For a country rule, for example, add a method on `ShipmentOptionsResolver` and an entry in its
`RULES` map. A delivery type rule needs no code, because the capabilities lookup sends the delivery
type.

### A toggle on the product

A product toggle is not generic: each one is a Magento EAV attribute with its own reader. Do these
steps in this order:

1. **Install the attribute.** Add a step in `Setup\UpgradeData::upgrade()`, gated with
   `version_compare($context->getVersion(), '<next release>', '<')`. Call
   `$eavSetup->addAttribute(Product::ENTITY, 'myparcel_<name>', …)` with `DEFAULT_ATTRIBUTES`, a
   `label`, a `note` and `'input' => 'boolean'`. Do not change an older step: an installed shop does
   not run it again. Do not change `setup_version` by hand; the release does that.
2. **Sort it.** Add `myparcel_<name>` to `Setup\Migrations\SortProductAttributes::ORDER`, and run
   `$this->sortProductAttributes->run($eavSetup)` at the end of the new step. The sort must run after
   the last `addAttribute`, or the new attribute goes to the end of the group.
3. **Translate it.** Add the `label` and the `note` to every file in `i18n/`.
4. **Name it for the readers.** Add a constant without the `myparcel_` prefix to
   `Service\ProductAttributes`, and add it to `ATTRIBUTES`, so that one load reads it with the others.
5. **Read it where it applies:**
   - At export, the attribute decides the option on the shipment: read it in a rule on
     `ShipmentOptionsResolver` (see "A rule that capabilities do not give"). `age_check` is the
     example.
   - At checkout, the attribute decides what the consumer may choose: read it in
     `Service\CartShippingRules`. `priority_delivery` is the example.
6. **Test it.** Test the reader with a product that has the attribute on, and with one that has it
   off.

Use `ProductAttributes` to read the attribute, never the EAV tables: Adobe Commerce keys those tables
on `row_id`.
