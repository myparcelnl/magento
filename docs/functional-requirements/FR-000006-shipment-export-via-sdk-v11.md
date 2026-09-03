# FR-000006: Shipment Export via SDK v11 Shipment Services

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000007](../user-stories/US-000007-admin-exports-mixed-store-batch.md), [US-000008](../user-stories/US-000008-admin-prints-one-merged-label-pdf.md), [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md), [US-000011](../user-stories/US-000011-order-status-updates-across-accounts.md)

## Description

Every existing shipment export capability must continue to work when the module is built on the SDK v11 shipment stack (`Model\Shipment\Shipment`, `Collection\ShipmentCollection`, `Services\Shipment\*`, `Services\Labels\*`, `Services\Returns\*`, `Services\MultiCollo\*`, `Services\TrackTrace\*`) instead of the removed consignment stack.

This is a **behaviour-preserving port**. It specifies no new merchant-facing capability; it specifies that nothing is lost. The capability-to-service mapping is defined in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md).

The export paths in scope, all of which exist today:

1. **Order view → create shipment.** The `sales_order_shipment_save_before` observer creates concepts and writes barcodes and MyParcel shipment ids back onto Magento shipment tracks.
2. **Order grid mass action.** Address pre-validation, Magento shipment creation, concept creation, label retrieval, track emails, and a PDF download.
3. **Shipment grid mass action.** As above, without address pre-validation and without the PPS branch.
4. **Create concept after invoice.** The `sales_order_invoice_pay` observer, gated on configuration and order state. Creates concepts; fetches no PDF.
5. **Return labels.** Both return-in-the-box (generated alongside an outbound shipment) and the admin return-label mail action.
6. **Multicollo.** A single shipment split into multiple colli where the account's capabilities permit it.
7. **Fulfilment / PPS export mode**, including order lines, customs declarations, pickup locations and order notes.
8. **Status cron.** Polling MyParcel for status and barcode updates and writing them back to Magento.

**Behaviour that must be preserved exactly:** which package type is chosen and from which source; how weight is calculated; how shipment options are resolved from configuration, product attributes and request parameters; the label description; label positions and paper size; how a pickup location is cleared when the carrier is overridden; the export statuses written into the `sales_order.track_status` and `track_number` grid columns; and the track & trace URL shown in the admin grid and shipment emails. The address-warning values that used to reach `track_status` are the one exception — they go with address validation, per BR-000003.

**Behaviour that is explicitly corrected rather than preserved.** Three defects are fixed rather than carried over:

1. **Customs items added twice** on some code paths, because `TrackTraceHolder::convertDataForCdCountry()` iterates both `$shipment->getData('items')` and `$shipment->getItems()`.
2. **The age-check precedence chain's lower two tiers are unreachable** — the product-attribute and carrier-default tiers never run, so only an explicit option takes effect. Diagnosis in DR-7 of the migration plan.
3. **The per-country print-position rule is removed**, not repaired: it returned the same answer for every order and the order grid never consulted it, so the two single-entity views disagreed with the grid. DR-10.

A fourth change is deliberate and larger than this FR: **pre-export address validation is removed**, specified in [BR-000003](../business-requirements/BR-000003-sdk-v11-compatibility.md) and DR-11. It is not an acceptance criterion here.

## User Impact

**Magento shop admins** should notice nothing. Every button produces the same result, with the same options, in the same number of clicks. That is the requirement.

**MyParcel support** benefits indirectly: on v11 the module can receive SDK regenerations, so a new carrier or shipment option no longer requires a module release to become usable.

## Acceptance Criteria

- [ ] Creating a shipment from the order view produces a MyParcel concept, and the Magento shipment track receives the MyParcel shipment id, status and barcode.
- [ ] The order grid mass action completes the full chain — Magento shipment creation, concept creation, label retrieval, track email, PDF download — and the downloaded PDF contains one label per expected collo.
- [ ] The shipment grid mass action produces the same labels for shipments that already exist.
- [ ] Create-concept-after-invoice creates a concept and fetches no PDF, unchanged from today.
- [ ] Return-in-the-box produces a return label whose description carries the parent's description and a validity date, and whose reference identifier matches the parent's.
- [ ] The admin return-label mail action sends a return label for each selected order.
- [ ] A shipment eligible for multicollo per the account's capabilities is created as one shipment with secondary shipments, not as N separate shipments.
- [ ] PPS export mode creates fulfilment orders carrying delivery options, recipient, invoice address, order lines, weight, order date, external identifier, pickup location where applicable, and a customs declaration for non-EU destinations.
- [ ] Order notes are exported per fulfilment order against that order's own account.
- [ ] The status cron updates `track_status`, `track_number` and MyParcel status for orders in both shipment and PPS modes.
- [ ] Package type, weight, shipment option and age-check resolution produce identical values to beta.15 for the same inputs, verified by the tests introduced before the port.
- [ ] Customs items appear exactly once per shipped item, correcting the current double-add.
- [ ] The track & trace URL rendered in the admin grid column and in shipment emails is unchanged, now produced by module-owned code rather than the removed `Sdk\Helper\TrackTraceUrl`.
- [ ] No reference to a removed SDK symbol remains, checked by the grep sweep in [TR-000005](../technical-requirements/TR-000005-sdk-v11-api-mapping.md), which owns the removed-class inventory and the paths to search.

## Priority

**Classification:** Must Have

**Justification:** This is the load-bearing requirement of BR-000003. Without it the module does not function on beta.31 at all, and every other FR in the set assumes it.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — the class-by-class replacement map, the constants the module now owns, and the removed-class inventory.
- [TR-000006 — Per-API-key export batching](../technical-requirements/TR-000006-per-api-key-export-batching.md) — how the services are driven, chunked and correlated back to Magento records.
- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — where multicollo eligibility and allowed options now come from.

### Notes

The v11 shipment stack already exists at beta.15 and is byte-identical to beta.31 for the model and collection, so this port can be built and verified against the currently installed SDK with the legacy stack still available as a reference. The composer pin moves last.

`Shipment` carries no API key. Each built shipment must be paired with the API key of its order's store; see FR-000007 and TR-000006.

## Dependencies

### Upstream (this FR depends on)

- SDK v11.0.0-beta.31.
- FR-000008 — the shipment builder needs capability data for multicollo eligibility and option availability.
- FR-000009 — supplies the insurance amount this FR writes into the shipment options.

### Downstream (depends on this FR)

- FR-000007 — multi-account batching orchestrates the services this FR ports to.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Phases 6, 7 and 8 of the [migration plan](../design/sdk-v11-migration.md). Phase 6 builds the shipment, Phase 7 orchestrates the services, Phase 8 aligns the fulfilment path.

The single most important verification is that the behaviour tests written in Phase 1 pass **unchanged** after the port. They assert our own decision rules rather than the wire payload, so a correct rewrite should not require editing them. If one needs editing to go green, that indicates a behaviour change and needs justifying rather than accommodating.
