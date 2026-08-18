# FR-000007: Multi-Account Batch Export

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000007](../user-stories/US-000007-admin-exports-mixed-store-batch.md), [US-000008](../user-stories/US-000008-admin-prints-one-merged-label-pdf.md), [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md), [US-000011](../user-stories/US-000011-order-status-updates-across-accounts.md)

## Description

A single admin action must be able to export orders belonging to different Magento stores that are configured with **different MyParcel API keys**, creating each shipment in its own MyParcel account, and returning one combined label PDF.

The API key is a store-scoped setting (`myparcelnl_magento_general/api/key`, visible at default, website and store-view scope), and no admin grid mass action filters by store. A mixed batch is therefore an ordinary occurrence, not an edge case.

SDK beta.15 provided this implicitly: the API key lived on each consignment and `MyParcelCollection` grouped by it before every request. At v11 the key is a constructor argument on each `final` service, a collection has no key concept, and no grouping exists. **The module must now own this behaviour.**

Required behaviour:

1. **Group by account.** Every order in a batch resolves its own API key from its store. Orders are grouped by key, and each group is sent to the MyParcel account that key belongs to. No order may be sent under another store's key.
2. **Combine the results.** Label PDFs retrieved per account are merged into a single document in the order the admin selected, so a mixed batch still yields one download.
3. **Report per order.** Success and failure are reported per order, identifying the Magento increment id, so an admin can tell which orders shipped when part of a batch fails.
4. **Cover every multi-account path**, not only label creation: status refresh, track & trace retrieval, return-label creation, concept deletion, and PPS order and order-note export.
5. **Refuse to guess.** An order whose store has no API key configured is reported with a clear, actionable message and excluded from the batch. It must never fall back to another store's key or to an ambient environment value — the SDK factory hazard that makes this a requirement rather than a nicety is defect 2 in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

## User Impact

**Multi-store merchants** — those running several webshops against several MyParcel accounts, typically per country or per brand — keep the ability to work from one order grid. Without this they must filter the grid by store before every export, and any mistake ships parcels against the wrong contract, producing wrong rates and a reconciliation problem that surfaces on the invoice rather than at export time.

**Single-account merchants**, the majority, see no change. The grouping degenerates to one group.

**MyParcel support** gains a clearer failure mode: a per-order report instead of a whole-batch error.

## Acceptance Criteria

- [ ] A mass action over orders from two stores with two different API keys creates each shipment in the MyParcel account matching its order's store, verified in both MyParcel backoffices.
- [ ] That mixed batch produces **one** PDF download containing every label, not one download per account.
- [ ] Labels in the merged PDF appear in the order the admin selected, not grouped by account.
- [ ] Three or more distinct API keys in one batch work the same way.
- [ ] Every distinct API key produces its own API client; no request is made with a key belonging to a different store.
- [ ] An order whose store has no API key is excluded, reported by increment id with an actionable message, and the remaining orders still export.
- [ ] No code path falls back to an environment-supplied key when a store's key is empty.
- [ ] Return labels for a mixed batch are created against each parent shipment's own account, correcting the current behaviour where all returns used the first order's key.
- [ ] The status cron polls **every** distinct API key across all configured stores, correcting the current behaviour where only one account is polled.
- [ ] Track & trace retrieval, status refresh and concept deletion are grouped by key.
- [ ] PPS export continues to create fulfilment orders and order notes against each order's own account.
- [ ] A per-order report is shown to the admin after a partially failed batch, distinguishing orders that shipped from orders that did not.

## Priority

**Classification:** Must Have

**Justification:** Without it, migrating to v11 is a functional regression for multi-store merchants — the SDK removed the behaviour and nothing else replaces it. Two of the acceptance criteria (return-label key, cron polling) also correct defects that exist today.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000006 — Per-API-key export batching](../technical-requirements/TR-000006-per-api-key-export-batching.md) — grouping rule, one client per key, chunking, PDF merge, and correlation back to Magento records.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — which services take a key and which do not.

### Notes

The module already resolves the API key per order correctly in most places (`TrackTraceHolder.php:118`, `MagentoOrderCollection.php:203`, `MagentoCollection.php:627`, `SalesOrderStatusHistoryObserver.php:55`). What is missing is the grouping on the send side, plus three call sites that read the key without a store id.

The fulfilment (PPS) path needs no change for grouping: `Collection\Fulfilment\OrderCollection::save()` still groups by per-order key at beta.31. Only the shipment path needs new code.

Label PDF merging requires `setasign/fpdi` as an explicit module dependency; see [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).

## Dependencies

### Upstream (this FR depends on)

- FR-000006 — the shipments being batched must exist first.
- SDK `Services\CoreApi\ShipmentApiFactory`, which builds a per-key client.

### Downstream (depends on this FR)

- None.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Phase 7 of the [migration plan](../design/sdk-v11-migration.md), with the PPS half in Phase 8.

Two things are easy to get wrong and are worth checking explicitly in review. First, build one API client per key and reuse it across all services for that key, rather than one per key-and-service pair. Second, an empty API key must fail loudly, before the SDK factory is reached. [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md) states both as rules and explains why injection is the defence.
