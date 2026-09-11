# FR-000006: Shipment Export via SDK v11 Shipment Services

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000007](../user-stories/US-000007-admin-exports-mixed-store-batch.md), [US-000008](../user-stories/US-000008-admin-prints-one-merged-label-pdf.md), [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md), [US-000011](../user-stories/US-000011-order-status-updates-across-accounts.md)

## Description

Every existing shipment export capability must continue to work when the module is built on the SDK v11 shipment stack (`Model\Shipment\Shipment`, `Collection\ShipmentCollection`, `Services\Shipment\*`, `Services\Labels\*`, `Services\Returns\*`, `Services\MultiCollo\*`, `Services\TrackTrace\*`) instead of the removed consignment stack.

This is a **behaviour-preserving port**. It specifies no new merchant-facing capability; it specifies that nothing is lost. The class-by-class mapping is in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md).

The export paths in scope:

1. **Order view → create shipment.** The `sales_order_shipment_save_before` observer creates concepts and writes barcodes and MyParcel shipment ids onto Magento shipment tracks.
2. **Order grid mass action.** Magento shipment creation, concept creation, label retrieval, track emails, PDF download.
3. **Shipment grid mass action.** As above, without the PPS branch.
4. **Create concept after invoice.** The `sales_order_invoice_pay` observer. Creates concepts; fetches no PDF.
5. **Return labels.** Return-in-the-box alongside an outbound shipment, and the admin return-label mail action.
6. **Multicollo.** One shipment split into colli where the account's capabilities permit it.
7. **Fulfilment / PPS export mode**, including order lines, customs declarations, pickup locations and order notes.
8. **Status cron.** Polling MyParcel for status and barcode updates and writing them back.

**Preserved exactly:** which package type is chosen and from which source; how weight is calculated; how shipment options are resolved from configuration, product attributes and request parameters; how the label description is composed; label positions and paper size; how a pickup location is cleared when the carrier is overridden; the export statuses written to the `sales_order.track_status` and `track_number` grid columns; and the track & trace URL in the grid and shipment emails. The address-warning values that used to reach `track_status` go with address validation, per BR-000003.

**Corrected rather than preserved:**

1. Customs items were added twice on some paths, because two item collections were iterated.
2. The age-check precedence chain's product-attribute and carrier-default tiers were unreachable; only an explicit option took effect.
3. The per-country print-position rule is removed: it answered the same for every order and the order grid never consulted it.
4. The label description used three characters fewer than the API accepts, and the return label's text did not fit the field at all.

A fifth change is larger than this FR: **pre-export address validation is removed**, specified in BR-000003.

## Acceptance Criteria

- [ ] Creating a shipment from the order view produces a MyParcel concept, and the Magento shipment track receives the MyParcel shipment id, status and barcode.
- [ ] The order grid mass action completes the full chain — Magento shipment creation, concept creation, label retrieval, track email, PDF download — and the PDF contains one label per expected collo.
- [ ] The shipment grid mass action produces the same labels for shipments that already exist.
- [ ] Create-concept-after-invoice creates a concept and fetches no PDF.
- [ ] Return-in-the-box produces a return label whose description carries the parent's description and a validity date, and whose reference identifier matches the parent's.
- [ ] The admin return-label mail action sends a return label for each selected order.
- [ ] A shipment eligible for multicollo per the account's capabilities is created as one shipment with secondary shipments, not as N shipments.
- [ ] PPS export mode creates fulfilment orders carrying delivery options, recipient, invoice address, order lines, weight, order date, external identifier, pickup location where applicable, and a customs declaration for non-EU destinations.
- [ ] Order notes are exported per fulfilment order against that order's own account.
- [ ] The status cron updates `track_status`, `track_number` and MyParcel status in both shipment and PPS modes.
- [ ] Package type, weight, shipment option and age-check resolution produce identical values to beta.15 for the same inputs, verified by the tests introduced before the port.
- [ ] Customs items appear exactly once per shipped item.
- [ ] The track & trace URL in the grid column and in shipment emails is unchanged, now produced by module-owned code.
- [ ] No reference to a removed SDK symbol remains, checked by the grep sweep in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md).

## Priority

**Classification:** Must Have

## Technical Considerations

### Referenced Technical Requirements

- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — the replacement map, the constants the module owns, and the removed-class inventory.
- [TR-000006 — Per-API-key export batching](../technical-requirements/TR-000006-per-api-key-export-batching.md) — how the services are driven, chunked and correlated back to Magento records.
- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — where multicollo eligibility and allowed options come from.

### Notes

`Shipment` carries no API key. Each built shipment is paired with the API key of its order's store; see FR-000007 and TR-000006.

## Dependencies

### Upstream (this FR depends on)

- SDK v11.0.0-beta.33.
- FR-000008 — the shipment builder needs capability data for multicollo eligibility and option availability.
- FR-000009 — supplies the insurance amount this FR writes into the shipment options.

### Downstream (depends on this FR)

- FR-000007 — multi-account batching orchestrates the services this FR ports to.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Design record: [SDK v11 migration](../design/sdk-v11-migration.md).

The behaviour tests written before the port assert the module's own decision rules rather than the wire payload, so they pass **unchanged** after it. A test that needs editing to go green indicates a behaviour change that needs justifying, not accommodating.
