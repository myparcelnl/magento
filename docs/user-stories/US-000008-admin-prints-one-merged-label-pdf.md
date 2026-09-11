# US-000008: Admin Prints One Merged Label PDF for a Mixed Batch

## Parent Functional Requirement

- **FR:** [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)

## Story

As a **Magento shop admin exporting orders from several MyParcel accounts at once**,
I want **one label PDF containing every label**,
So that **I can send a single document to the printer instead of juggling one download per account**.

## Acceptance Criteria

### Scenario 1: Mixed batch yields a single document

**Given** a batch of six orders, four from a store on API key `KEY_A` and two on `KEY_B`,
**When** I run *Print MyParcel label*,
**Then** exactly one PDF is downloaded,
**And** it contains six labels,
**And** no second download is triggered.

### Scenario 2: Each account's labels stay together

**Given** I selected orders in the sequence A1, B1, A2, B2,
**When** the merged PDF is produced,
**Then** the labels of account A follow each other in the order their ids were sent, and so do account B's,
**And** the two accounts appear as consecutive blocks. Interleaving by selection order is not possible on A4, where one sheet carries four labels of one account (TR-000006).

### Scenario 3: A4 label positions still work

**Given** paper size A4 and a starting position of 3,
**When** I export a mixed batch,
**Then** the merged document honours the requested start position exactly as it does for a single-account batch.

### Scenario 4: A6 is unaffected

**Given** paper size A6,
**When** I export a mixed batch,
**Then** the merged document contains one label per page and no position handling is applied.

### Scenario 5: Open-in-new-tab behaves as before

**Given** the request type is *open in new tab* rather than download,
**When** I export a mixed batch,
**Then** the merged PDF is rendered inline in the browser, as it is for a single account.

### Scenario 6: One account still produces an unmerged path

**Given** a batch with a single API key,
**When** I export it,
**Then** the resulting PDF is byte-comparable to the pre-migration output for the same orders,
**And** no unnecessary merge step is applied.

## Technical Notes

- SDK beta.15's `MyParcelCollection::setPdfOfLabels()` merged per-account PDFs internally. In v11 `ShipmentLabelsService` holds a single PDF string per instance, so merging happens in module code (`Service\Export\LabelPdfMerger`).
- Merge specification in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md), including why accounts are blocks rather than interleaved.
- The export answers JSON and the labels are fetched by a second request, so the grid keeps its selection and reloads once the barcodes exist.
- Scenario 6 matters because most merchants are single-account and any regression there affects everyone.

## Dependencies

- [US-000007](US-000007-admin-exports-mixed-store-batch.md) — the shipments must be created in the right accounts before their labels can be merged.

## Definition of Done

- [ ] All six scenarios verified manually with two real MyParcel accounts on acceptance credentials.
- [ ] Unit test asserts a merged document's page count equals the sum of its inputs and that each account's pages stay together in send order.
- [ ] `setasign/fpdi` added to `composer.json` with an explicit constraint.
- [ ] Single-account output compared against a pre-migration PDF for the same orders.
- [ ] Documentation updated (this US, FR-000007, TR-000006).
