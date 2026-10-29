# Shipment options modal

INT-1289. With capabilities, the API refuses a shipment whose options the account does not carry. The modal lets a merchant see and change an order's shipment options, save them on the order, and export or retry with them. It works like the PDK modal in WooCommerce and PrestaShop.

## Where it opens

| Place | Actions |
|---|---|
| Order grid: row action "Change MyParcel options", mass action "Print MyParcel labels" | Save · Save & export |
| Shipment grid: mass action "Print MyParcel labels" | Save · Save & export |
| Order view and shipment view: "Change MyParcel options" beside the shipping information, and "Print label" | Save · Save & export |
| New Shipment page: "Change MyParcel options" below the summary | Save |

On a shipment page, "Save & export" is the retry. The shipment export sends every track without a MyParcel id again, and that is the state a refused export leaves.

## Storage

The options live on the order, in `sales_order.myparcel_delivery_options`, so `/V1/myparcel/delivery-options` shows them. A shipment has no options of its own.

A stored shipment option is tri-state: `null` inherits, `true` is on, `false` is off. A stored `false` beats everything, an 18+ product included: the merchant decides (`DefaultOptions::sourceOf()`).

An option the merchant switches on is also listed in `merchantOptions`, so it outranks the customer's checkout choice when the two exclude each other. An 18+ product still outranks it. Switching the option off removes it from the list.

The checkout widget writes `false` for an option it offered that the customer did not tick. That `false` must inherit, so every checkout write path turns it into `null` (`DeliveryOptions::inheritUnticked()`):

- `ShippingMethods::getFromDeliveryOptions()` (quote)
- `ShippingInformationManagementPlugin` (quote)
- `SaveOrderBeforeSalesModelQuoteObserver` (order)

The 5.11.0 data upgrade does the same to rows written before (`Setup\Migrations\ShipmentOptionsFalseToNull`, all orders and active quotes). The legacy checkout shape is read through `ShipmentOptions::fromLegacyCheckoutData()`, which reads its `false` and `0` as inherit.

Save writes only the fields the merchant changed (`OrderOptionsWriter`, `OptionChanges`). Untouched options keep inheriting, so a later configuration change still reaches them. Besides the options, the order keeps:

- the carrier and package type (top-level keys, with `myparcel_carrier` kept in step);
- an insurance amount (`shipmentOptions.insurance`, `0` is off);
- `labelAmount`, `digitalStampWeight` and `physicalProperties` (length, width and height in whole cm), appended to `DeliveryOptions::toArray()` only when set;
- the delivery date (`date`): a change stores `Y-m-d 00:00:00`, a removal stores `null`. `sales_order.drop_off_day` follows, by the checkout's rule (`DeliveryRepository::dropOffTimestampFor()`).

A carrier change on a pickup order removes the pickup location and makes the order a standard delivery (`DeliveryOptions::withCarrier()`, shared with the export).

## At export

- An option the carrier does not offer for the shipment is left off and logged, whatever switched it on (`ShipmentOptionsResolver::dropNotOffered()`), as the PDK does.
- An empty or removed date sends no delivery date (`Dating::convertDeliveryDate()`). A date that has passed still becomes tomorrow.
- No delivery date goes to DPD or bpost, or together with collect: the API refuses it (`OrderShipmentOptions::acceptsDeliveryDate()`, the PDK's `DeliveryDateExceptionCalculator`).
- Saved dimensions go with the weight on a label (`ShipmentBuilder`) and on an order v1 order (`FulfilmentOrderBuilder`). Without them a label sends none, and order v1 keeps the SDK's 10 x 10 x 10 cm.
- Capabilities report dimension ranges but no "required" flag, so the form always shows the three fields and the API decides.

## Validation

`OrderOptionsWriter` checks every order against its own account and country before it writes any order:

- the carrier is exportable and in the contract;
- the package type is offered for that carrier;
- each option switched on is offered for the shape;
- the insurance amount is within `InsuranceRange`;
- a delivery date is tomorrow or later.

Switching an option off is always allowed. Unverified (permissive) capabilities check nothing, the same as the New Shipment form. A selection that spans more than one API key is refused.

## The form

`ViewModel\ShipmentOptionsForm` holds the capability logic that `Block\Sales\NewShipment` had, for one order or a bulk selection.

- **One order:** per carrier and package type. Each checkbox starts at what the export would send now (`DefaultOptions::hasOptionSet()`). Only an option the contract marks required is locked: the API refuses the shipment without it.
- **Bulk:** one flat list of Keep / On / Off selects over the union of what the account's contract offers. Each order is checked on save.

`shipment-options.js` loads the form (`myparcel/shipmentOptions/form`) and posts only the fields that differ from `data-initial` (`myparcel/shipmentOptions/save`).

## Refusal reason

`sales_order.myparcel_export_error` (mirrored to `sales_order_grid`) keeps why the last export of an order failed, until an export of it succeeds (`Service\Export\ExportErrorRecorder`). The order grid shows it in the "MyParcel export error" column. The order view, the shipment view and the New Shipment page show it above the options.

Recorded per path:

- **Shipment export:** the reasons the API blamed on the order, and a local build failure. An order that only shared a refused chunk keeps what it had.
- **Order v1 (PPS):** the API refuses a chunk whole and names no order, so every order of the refused chunk gets the message.
