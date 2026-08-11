# US-000007: Admin Exports Orders From Several Stores in One Action

## Parent Functional Requirement

- **FR:** [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)

## Story

As a **Magento shop admin running several stores against several MyParcel accounts**,
I want **to select orders from any of those stores in the order grid and export them in one mass action**,
So that **I can work from a single queue instead of filtering the grid by store before every export, without risking parcels being shipped against the wrong MyParcel contract**.

## Acceptance Criteria

### Scenario 1: Two stores, two API keys, one action

**Given** store A is configured with API key `KEY_A` and store B with API key `KEY_B` at store-view scope,
**And** the order grid contains unshipped orders from both stores,
**When** I select two orders from store A and two from store B and run *Print MyParcel label*,
**Then** the two store A shipments appear in the MyParcel backoffice of `KEY_A`'s account and not in `KEY_B`'s,
**And** the two store B shipments appear in `KEY_B`'s account and not in `KEY_A`'s,
**And** each Magento shipment track receives the MyParcel shipment id issued by its own account.

### Scenario 2: One create call per account

**Given** a batch spanning three distinct API keys,
**When** the export runs,
**Then** exactly three shipment-create calls are made,
**And** each call carries only the shipments belonging to its own key,
**And** each call is made through an API client constructed with that key.

### Scenario 3: A store without an API key does not stop the batch

**Given** store C has no API key configured at any scope,
**And** I select orders from store A and store C,
**When** I run the mass action,
**Then** the store A orders are exported normally,
**And** the store C orders are excluded and reported by increment id with a message telling me to configure an API key,
**And** no store C order is sent under store A's key.

### Scenario 4: No fallback to an ambient key

**Given** the environment variables `API_KEY`, `API_KEY_NL` and `API_KEY_BE` are set to a decoy account's key,
**And** store C has no API key configured,
**When** I export a store C order,
**Then** the export fails for that order with the module's "API key is not known" message,
**And** no request is made to the decoy account.

### Scenario 5: Single-account merchants are unaffected

**Given** a single-store install with one API key,
**When** I export any batch,
**Then** exactly one create call per chunk is made and behaviour is identical to before the migration.

### Scenario 6: Return labels use the parent's account

**Given** a mixed batch from store A and store B with return-in-the-box enabled,
**When** the export runs,
**Then** each return label is created in the same account as its parent shipment,
**And** no return label is created in the account of whichever order happened to be first.

## Story Points

**Estimate:** 8
**Complexity:** High

## Technical Notes

- Grouping, client construction and the empty-key guard are specified in [TR-000006](../technical-requirements/TR-000006-per-api-key-export-batching.md).
- The API key is resolved per order from `myparcelnl_magento_general/api/key` at the order's store scope. The module already does this correctly in `TrackTraceHolder.php:118` and `MagentoCollection.php:628`; what is new is the grouping on the send side.
- Scenario 4 is not hypothetical. `ShipmentApiFactory::resolveApiKey()` falls back to those environment variables when handed an empty string, so the guard must run before the SDK factory is reached.
- Scenario 6 corrects a defect that exists today: the removed `generateReturnConsignments()` used only the first consignment's key.

## Dependencies

- [US-000008](US-000008-admin-prints-one-merged-label-pdf.md) — the same mass action must also produce one merged PDF; both are needed for the action to be usable.
- [US-000009](US-000009-admin-sees-clear-error-for-missing-api-key.md) — expands scenarios 3 and 4.

## Definition of Done

- [ ] All six scenarios verified manually against acceptance credentials with two real MyParcel accounts.
- [ ] Unit tests with a mocked `ShipmentApi` cover scenarios 2, 4 and 6.
- [ ] Batch export logs the number of distinct accounts and the per-account outcome, without logging any API key.
- [ ] Code reviewed against TR-000006's requirement of one client per key, reused across services.
- [ ] Documentation updated (this US, FR-000007, TR-000006).
