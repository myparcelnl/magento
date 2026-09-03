# US-000011: Order Status Updates Reach Every MyParcel Account

## Parent Functional Requirement

- **FR:** [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)
- **Also serves:** [FR-000006 — Shipment export via SDK v11 shipment services](../functional-requirements/FR-000006-shipment-export-via-sdk-v11.md)

## Story

As a **Magento shop admin running several stores against several MyParcel accounts**,
I want **the status cron to update orders from all of my accounts**,
So that **track & trace numbers and shipment statuses appear in Magento for every store, not only the one that happens to match the default-scope API key**.

## Acceptance Criteria

### Scenario 1: The cron polls every configured account

**Given** store A is configured with `KEY_A` and store B with `KEY_B`,
**And** both stores have orders exported to MyParcel awaiting status updates,
**When** the `UpdateStatus` cron runs,
**Then** orders from store A are updated from `KEY_A`'s account,
**And** orders from store B are updated from `KEY_B`'s account,
**And** neither store's orders are queried under the other's key.

### Scenario 2: PPS mode polls per account

**Given** the export mode is PPS/fulfilment,
**And** orders exist for two accounts,
**When** the cron runs,
**Then** the fulfilment order query is issued once per distinct API key,
**And** both stores' orders receive their barcode and status.

### Scenario 3: Shipment mode polls per account

**Given** the export mode is shipments,
**And** orders exist for two accounts,
**When** the cron runs,
**Then** status and barcode retrieval is grouped by API key,
**And** both stores' Magento shipment tracks are updated.

### Scenario 4: One account failing does not stop the others

**Given** two accounts, and `KEY_A`'s account returns an error,
**When** the cron runs,
**Then** store B's orders are still updated,
**And** the `KEY_A` failure is logged,
**And** the cron does not abort.

### Scenario 5: Grid columns reflect the update for every store

**Given** the cron has run across two accounts,
**When** I view the order grid,
**Then** `track_status` and `track_number` are populated for orders from both stores.

### Scenario 6: PPS order lines stay with their own order

**Given** a PPS export of several orders in one batch,
**When** the fulfilment orders are created,
**Then** each carries only its own order lines,
**And** lines do not accumulate across orders in the batch.

## Story Points

**Estimate:** 5
**Complexity:** Medium

## Technical Notes

- This story corrects a defect that exists today: `src/Cron/UpdateStatus.php:126` calls the fulfilment order query with an API key read without a store id, so only one account is ever polled. On a multi-account install the other accounts' orders never receive status updates.
- Scenario 6 corrects a second pre-existing defect: `MagentoOrderCollection.php:166` creates the order-lines collection once *outside* the per-order loop, so lines accumulate across orders in a multi-order PPS batch.
- The fulfilment path's own per-order key grouping still works at beta.31 (`Collection\Fulfilment\OrderCollection::save()` is unchanged), so scenario 2 needs the query side fixed, not the save side.
- Beta.31 also changes `Model\Fulfilment\AbstractOrder`: `getDeliveryOptions()` now returns `Model\Shipment\ShipmentOptions`, and **`getCarrier()` throws unless `setCarrierId()` was called**. The fulfilment export must set the carrier id explicitly.
- Grouping requirements are in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).

## Dependencies

- [US-000007](US-000007-admin-exports-mixed-store-batch.md) — shipments must be created in the correct accounts before their statuses can be polled from those accounts.

## Definition of Done

- [ ] All six scenarios verified with two accounts on acceptance credentials, in both export modes.
- [ ] Unit test asserts the cron issues one query per distinct API key and that one account's failure does not abort the run.
- [ ] Unit test asserts a multi-order PPS batch gives each fulfilment order only its own lines.
- [ ] `setCarrierId()` is set on every fulfilment order, verified by a test that would otherwise hit the new `getCarrier()` exception.
- [ ] Documentation updated (this US, FR-000007, TR-000006).
