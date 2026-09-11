# FR-000007: Multi-Account Batch Export

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000007](../user-stories/US-000007-admin-exports-mixed-store-batch.md), [US-000008](../user-stories/US-000008-admin-prints-one-merged-label-pdf.md), [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md), [US-000011](../user-stories/US-000011-order-status-updates-across-accounts.md)

## Description

A single admin action must export orders belonging to different Magento stores configured with **different MyParcel API keys**, creating each shipment in its own MyParcel account and returning one combined label PDF.

The API key is a store-scoped setting (`myparcelnl_magento_general/api/key`), and no admin grid mass action filters by store, so a mixed batch is an ordinary occurrence. SDK beta.15 grouped consignments by key inside `MyParcelCollection`; in v11 the key is a constructor argument on each service and no grouping exists. **The module owns this behaviour.**

Required behaviour:

1. **Group by account.** Every order resolves its own API key from its store. Orders are grouped by key, and each group is sent to that key's account. No order is sent under another store's key.
2. **Combine the results.** Label PDFs retrieved per account are merged into one document.
3. **Report per order.** Success and failure are reported per order by increment id.
4. **Cover every multi-account path**: status refresh, track & trace retrieval, return-label creation, concept deletion, and PPS order and order-note export.
5. **Refuse to guess.** An order whose store has no API key is reported with an actionable message and excluded. It never falls back to another store's key or to an environment value; the SDK factory hazard behind this rule is defect 2 in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

## Acceptance Criteria

- [ ] A mass action over orders from two stores with two different API keys creates each shipment in the account matching its order's store, verified in both backoffices.
- [ ] That mixed batch produces **one** PDF download containing every label.
- [ ] Labels of one account follow each other in the merged PDF, in the order that account's ids were sent (see TR-000006 for why interleaving by selection order is not possible).
- [ ] Three or more distinct API keys in one batch work the same way.
- [ ] Every distinct API key produces its own API client; no request is made with another store's key.
- [ ] An order whose store has no API key is excluded, reported by increment id, and the remaining orders still export.
- [ ] No code path falls back to an environment-supplied key when a store's key is empty.
- [ ] Return labels for a mixed batch are created against each parent shipment's own account.
- [ ] The status cron polls **every** distinct API key across all configured stores.
- [ ] Track & trace retrieval, status refresh and concept deletion are grouped by key.
- [ ] PPS export creates fulfilment orders and order notes against each order's own account.
- [ ] A per-order report is shown after a partially failed batch, distinguishing orders that shipped from orders that did not.

## Priority

**Classification:** Must Have

## Technical Considerations

### Referenced Technical Requirements

- [TR-000006 — Per-API-key export batching](../technical-requirements/TR-000006-per-api-key-export-batching.md) — grouping rule, one client per key, chunking, PDF merge, correlation back to Magento records.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — which services take a key and which do not.

### Notes

The fulfilment (PPS) path needs no change to *route* orders: `Collection\Fulfilment\OrderCollection::save()` groups by per-order key. It needs one to *isolate* them: the grouped calls are one method, so a failure on the second account escaped before the first account's orders were marked exported, and the next run created them again. `MagentoOrderCollection::setFulfilment()` therefore groups and saves per key itself.

Label PDF merging requires `setasign/fpdi` as an explicit module dependency; see TR-000006.

## Dependencies

### Upstream (this FR depends on)

- FR-000006 — the shipments being batched must exist first.
- SDK `Services\CoreApi\ShipmentApiFactory`, which builds a per-key client.

### Downstream (depends on this FR)

- None.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Design record: [SDK v11 migration](../design/sdk-v11-migration.md).

Two things are easy to get wrong in review. Build one API client per key and reuse it across all services for that key, rather than one per key-and-service pair. An empty API key must fail loudly before the SDK factory is reached. TR-000006 states both as rules.
