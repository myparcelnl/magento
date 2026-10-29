# Shipment options modal

The modal lets a merchant change the MyParcel options of an order, save them on the order, and
export or retry with them, like the PDK modal in WooCommerce and PrestaShop. On a shipment page,
"Save & export" is the retry: the export sends every track without a MyParcel id again.

## Storage

The options live on the order, in `sales_order.myparcel_delivery_options`. A shipment has none.
A stored shipment option is tri-state: `null` inherits, `true` is on, `false` is off. A stored
`false` beats every default, an 18+ product included.

- The checkout widget writes `false` for an option the customer did not tick. Every checkout write
  turns it into `null` (`DeliveryOptions::inheritUnticked()`), and the 5.11.0 upgrade does the same
  to older rows (`Setup\Migrations\ShipmentOptionsFalseToNull`).
- A save writes only the fields the merchant changed (`OptionChanges`), so a later configuration
  change still reaches the others.
- An option the merchant switches on also goes in `merchantOptions`, which ranks it above the
  checkout in an `excludes` conflict (`OptionSource`).

## Delivery

The modal of one order has one "Delivery" list, which the refresh endpoint fills
(`Controller/Adminhtml/ShipmentOptions/Refresh`, `DeliveryList`):

- "No date" holds standard and express. Each date that `/delivery_options` offers gets a group, and
  "Pickup" opens a list of the locations near the shipping address (`PickupLocations`).
- Capabilities decide which delivery types and options the selection allows. The lookup sends the
  delivery type and the ticked options. An option that the selection does not allow is greyed out,
  not hidden, and a save sends it as off. When the merchant ticks an option, the modal also ticks the
  options in its `requires` list.
- The list preselects the stored delivery. When the list does not offer it, for example because the
  date is in the past, the list preselects standard without a date. On open, the option block
  follows the preselected delivery.
- The modal asks for the dates on open and on a carrier or package type change. A change of delivery
  or of an option refreshes only what capabilities allow.
- When the dates cannot be read, the stored delivery stays in the list, with a warning.
- A save sends the delivery only when it is different from the stored one. `drop_off_day` follows a
  changed date.
- The bulk modal (more than one order) changes no delivery.

## Export

- A stored date before tomorrow, in the timezone of the order's store, is sent as no date
  (`Dating::convertDeliveryDate()`), as in the PDK. The API refuses a morning or evening delivery
  without a date, and the merchant corrects it in the modal.
- Saved dimensions go on a label and on an order v1 order. Without them, a label sends none and
  order v1 sends the SDK's 10 x 10 x 10 cm. Capabilities give no "required" flag for dimensions, so
  the form always shows the three fields and the API decides.
- `sales_order.myparcel_export_error` keeps why the last export of an order failed
  (`ExportErrorRecorder`). Order v1 refuses a chunk whole and names no order, so every order in the
  chunk gets the message.
