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

## Export

- Saved dimensions go on a label and on an order v1 order. Without them, a label sends none and
  order v1 sends the SDK's 10 x 10 x 10 cm. Capabilities give no "required" flag for dimensions, so
  the form always shows the three fields and the API decides.
- `sales_order.myparcel_export_error` keeps why the last export of an order failed
  (`ExportErrorRecorder`). Order v1 refuses a chunk whole and names no order, so every order in the
  chunk gets the message.
