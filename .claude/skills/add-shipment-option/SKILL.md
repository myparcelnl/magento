---
name: add-shipment-option
description: Guide step by step through adding a shipment option to the Magento module, including a toggle on the product. Use when a new option must reach the shipment options modal, the checkout, the export or the product page.
---

# Add a shipment option

The steps are in "Adding a shipment option" in `CONTRIBUTING.md`. Read that section first. This skill
asks which of its parts apply, then does only those.

## Ask first

Ask these questions one at a time. Ask all of them.

1. **SDK key.** "What is the snake_case key in `RefShipmentShipmentOptions::setters()`?" If the SDK
   does not have it, stop: the SDK must be updated first. Do not use a plain string for an API option.
2. **Label.** "Does the option need a label other than its name?"
3. **Fee.** "Does the merchant charge for it: a checkout fee, a from-price, or neither?"
   A checkout fee also needs the widget to support the option.
4. **Mailbox.** "Can it ship in a mailbox, with its settings in the mailbox group?"
5. **Fail-open.** "Must the form show it when capabilities cannot be read?"
6. **Rule.** "Does a rule decide it that capabilities do not give, such as a country rule?"
7. **Product toggle.** "Must a product be able to switch it on? If yes: does the product decide the
   option at export, or what the consumer may choose at checkout?"

## Then do

For each yes, follow the matching subsection of `CONTRIBUTING.md`:

| Answer | Subsection |
|---|---|
| 2, 3, 4, 5 | A row in `ShipmentOption::DEFINITIONS`: only the facts these answers need |
| 3 (checkout fee) | An option in the checkout |
| 6 | A rule that capabilities do not give |
| 7 | A toggle on the product |

Then run `vendor/bin/pest`. `ShipmentOptionParityTest` checks that every `toggle` option in
`ShipmentOption::DEFINITIONS` reaches the shipment that the API receives.

## Do not

- Do not set a fact in `ShipmentOption::DEFINITIONS` that the answers do not need, and do not add a
  list of options anywhere else: add a fact to the table instead.
- Do not add a per-carrier exception. A fact about a carrier comes from capabilities.
- Do not change an older `UpgradeData` step or `setup_version`.
