# US-000009: Admin Gets a Clear, Per-Order Report When an Export Partly Fails

## Parent Functional Requirement

- **FR:** [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)
- **Also serves:** [FR-000010 — Graceful degradation on capability changes](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md)

## Story

As a **Magento shop admin exporting a batch of orders**,
I want **to be told exactly which orders shipped and which did not, and why**,
So that **I can fix and retry only the failures instead of guessing whether re-running the whole batch will create duplicate shipments**.

## Acceptance Criteria

### Scenario 1: Missing API key names the store and the orders

**Given** store C has no API key configured at any scope,
**When** I include store C orders in a mass action,
**Then** a message identifies the affected orders by increment id,
**And** it states that the store has no MyParcel API key and where to configure it,
**And** the orders from other stores in the same batch are still exported.

### Scenario 2: A failure mid-batch does not lose earlier work

**Given** a batch of 50 orders with a chunk size of 20,
**And** an order in the third chunk has an address the API rejects,
**When** the export runs,
**Then** the 40 shipments from chunks 1 and 2 are recorded against their Magento orders with shipment ids and barcodes,
**And** the report lists those 40 as succeeded,
**And** it lists the failing order with the API's own message,
**And** re-running the action over the failed orders does not duplicate the 40 already created.

### Scenario 3: An API rejection is shown verbatim, not flattened

**Given** an order requests a shipment option the account's contract does not permit,
**When** the export runs,
**Then** the API's error message is shown to me alongside that order's increment id,
**And** it is not replaced by a generic "export failed" message.

### Scenario 4: Capabilities being unavailable does not block the export

**Given** the capabilities endpoint returns HTTP 500,
**When** I export an order,
**Then** the label is still created,
**And** no error is shown to me about capabilities,
**And** the failure is recorded in the log.

### Scenario 5: An unknown option from the API is not an error

**Given** the capabilities response contains a shipment option this module version does not recognise,
**When** I open the *New Shipment* form,
**Then** the form renders normally without the unknown option,
**And** no error is shown,
**And** the unknown option key appears in the log.

### Scenario 6: Nothing exportable produces a clear message, not silence

**Given** I select only orders that cannot be exported,
**When** I run the mass action,
**Then** I get a message explaining why none were exported,
**And** no empty PDF is downloaded.

## Story Points

**Estimate:** 5
**Complexity:** Medium

## Technical Notes

- Per-chunk persistence and the per-order report are specified in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- Scenarios 4 and 5 implement the fail-open rules in [FR-000010](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md); logging uses the module's `Logger` facade, with unknown enum values routed through the SDK's `Support\EnumFallback` listener.
- Scenario 3 guards the trade FR-000010 accepts knowingly: because the module offers options without pre-validating them against capability data, the API's rejection is the merchant's only feedback. Swallowing or genericising it breaks the whole approach.
- Scenario 2's resumability depends on the API treating reference identifiers idempotently. **Confirm against acceptance** before relying on it; if it does not hold, the report must warn against blind re-runs.
- The module currently catches `\Throwable` broadly in several export paths and adds a single message. That pattern loses per-order attribution and needs revisiting here.

## Dependencies

- [US-000007](US-000007-admin-exports-mixed-store-batch.md) — grouping must exist before per-account failures can be attributed.

## Definition of Done

- [ ] All six scenarios verified, using fault injection for scenarios 3 through 5.
- [ ] Unit tests cover per-chunk persistence on a mid-batch failure and per-order attribution in the report.
- [ ] Confirmed whether re-running over failed orders duplicates shipments; result recorded in TR-000006's Assumptions.
- [ ] No broad `\Throwable` catch remains in an export path that discards which order failed.
- [ ] Documentation updated (this US, FR-000007, FR-000010, TR-000006).
