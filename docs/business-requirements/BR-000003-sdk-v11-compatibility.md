# BR-000003: MyParcel Magento Module Runs on MyParcel SDK v11

## Business Context

The Magento module is an adapter over the MyParcel PHP SDK (`myparcelnl/sdk`). The SDK's v11 line replaced its hand-written consignment stack with generated API clients and typed services, and in **beta.22** it deleted the legacy stack. The module was pinned to `11.0.0-beta.15@beta` because every later release removes classes it depended on.

While that pin held, the module could not adopt anything the SDK added after beta.15 (capabilities, contract definitions, webhooks), could not receive SDK bug fixes or regenerated clients, and diverged from `myparcelnl/pdk`, which already runs on the v11 line.

The SDK's removal of `MyParcelCollection` also removed a capability the module relied on without owning: **exporting shipments for several MyParcel accounts in one action**. In v11 the API key is a constructor argument on each service, so batching by account is the consumer's job.

## Objective

The module runs on `myparcelnl/sdk` **v11.0.0-beta.33**, with multi-account batch export re-implemented inside the module, and with one deliberate capability removal: pre-export address validation.

A merchant running several Magento stores against several MyParcel accounts can select orders across those stores in one admin action and receive one merged label PDF, as before.

## Business Justification

- **Removing a release blocker.** The pin blocked every future SDK adoption, and the breaking change is a single cliff at beta.22, so waiting did not reduce the cost.
- **Carrier and option changes reach merchants.** On v11 package types, options and carriers arrive from the capabilities endpoint per account instead of from module code.
- **Per-account correctness for multi-store merchants.** Without re-implemented batching, a merchant with two MyParcel accounts ships one account's parcels against the other's contract, or loses batching.
- **Convergence with the PDK.** Sharing an SDK major means carrier changes are validated once rather than per integration.

## Scope

### In Scope

- Compatibility with SDK v11: shipment creation, label retrieval, track & trace, return shipments, multicollo, and the fulfilment (PPS) export path.
- **Multi-account batch export**, re-implemented in the module: grouping by the API key each order's store resolves, one API client per key, and one merged label PDF.
- **Capability data sourced from the API** per account: package types, delivery types, shipment options, collo maximum and insurance bounds.
- **Insurance as a range.** Any amount within the account's contract minimum and maximum replaces the fixed per-carrier tiers. This is a user-visible admin change.
- **Graceful degradation.** The module keeps exporting when the capabilities response changes shape, contains unknown values, or is unavailable.
- **A configurable export chunk size**, default 20, because large single batches time out.
- **Pre-export address validation is removed.** `ValidatePostalCode` is deleted in the SDK and `ValidateStreet` survives; keeping half a check is worse than none, so the API is the single authority on address validity. Merchants lose the "please check street" and "please check postal code" grid warnings, and a malformed address no longer drops its order out of a mass action before export.
- Pre-existing defects in the same area: PPS status polling reaching one account only; return labels created against the wrong account in a mixed batch; PPS order lines accumulating across a batch; a repeated concept mass action creating duplicate billable shipments; customs items added twice; the age-check precedence chain's lower tiers being unreachable.

### Out of Scope

- **Fixing the SDK.** Defects found during the migration are reported as issues and worked around in the module. No SDK pull requests.
- **Any change to the checkout delivery-options widget contract**, the versioned REST API response shape, or the admin configuration structure beyond the insurance field.
- **Retiring the `@internal` SDK classes** the module still uses (`AccountWebService`, `CarrierOptionsWebService`, `OrderCollection`).
- **Hashing the API key out of the `account_settings_{apiKey}` config path.** Related, and landed first as #967.

## Success Criteria

- [ ] `composer.json` requires `myparcelnl/sdk: 11.0.0-beta.33@beta`, and `setup:di:compile`, `setup:upgrade` and the Pest suite pass on PHP 8.1 through 8.4.
- [ ] Every export path that worked on beta.15 works: order-view shipment creation, both admin grid mass actions, create-concept-after-invoice, the return-label mail action, the status cron, and PPS export mode.
- [ ] A single mass action spanning orders from two stores with **two different MyParcel API keys** creates each shipment in its correct account and returns **one merged PDF**.
- [ ] A batch larger than the chunk size completes without timing out, and a failure in one chunk leaves the shipments created by earlier chunks recorded against their Magento orders.
- [ ] The admin *New Shipment* form offers the package types, delivery types and shipment options the account has, per carrier.
- [ ] An admin can enter any insurance amount within the contract's range and export with it.
- [ ] A capabilities response containing an option, carrier or package type the module does not recognise breaks neither the admin form, the checkout nor an export. The unknown value is logged.
- [ ] With the capabilities endpoint unreachable, label creation still succeeds.
- [ ] The status cron updates orders across **all** configured MyParcel accounts.
- [ ] An order with a malformed street or postcode is accepted, shows no grid warning, and surfaces the API's own rejection at export.
- [ ] No merchant configuration is lost or reset by the upgrade; saved insurance amounts remain valid.

## Stakeholders

| Role | Name | Responsibility |
|---|---|---|
| Business Sponsor | MyParcel External Integrations team | Funds and prioritises this work |
| Product Owner | MyParcel platform PM | Accepts the insurance UI change and the degradation behaviour |
| Technical Lead | MyParcel Magento module maintainer | Feasibility and design decisions |
| Supplier | MyParcel PHP SDK team | Owns the reported defects and the capabilities contract |
| End Users | MyParcel customers (Magento shop admins), especially multi-store merchants | Validation |

## Constraints

- **Technical:** The module's PHP floor stays `^7.4 || ^8.0`. No `vendor/**` file may be modified. The generated SDK client requires `guzzlehttp/guzzle ^7.10`, which the target Magento versions satisfy.
- **Delivery:** The module is broken against v11 until the migration completes, so this is one deliverable, not a series of releasable increments.
- **Testing:** Live verification uses `*.acceptance.myparcel.nl` credentials only.
- **Compatibility:** Existing merchant configuration must survive the upgrade without a manual re-import.

## Dependencies

- SDK tag `v11.0.0-beta.33` and its `UPGRADE.md`.
- The MyParcel Core API capabilities and contract-definitions endpoints, `version=2` response format.
- `myparcelnl/pdk` as the reference implementation for the capabilities client and the storage split.
- #967, the API-key hashing pull request.

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| The capabilities endpoint cannot answer something a consignment class used to | Medium | High | The PDK proves the endpoint covers the questions asked. Gaps are raised as questions and recorded as assumptions in TR-000005. |
| Silent behaviour drift during a large refactor with no prior test coverage | High | High | The module's own decision rules were pinned in tests before the refactor and must pass unchanged after it. |
| A future capabilities change breaks the module, as it has for sibling integrations | Medium | High | FR-000010: capabilities inform the UI and never gate an export; unknown values pass through and are logged; the module fails open. |
| Multi-account batching ships parcels against the wrong contract | Medium | High | TR-000006 makes grouping explicit and one client per key mandatory. Tests assert N keys produce N create calls; the two-store manual test is a release gate. |
| A mid-batch failure leaves shipments in MyParcel with no Magento reference | Medium | Medium | Per-chunk persistence before the next chunk is issued, plus a per-order report. |
| The insurance field change invalidates saved values | Low | Low | The new domain is a superset of the old. Out-of-range values clamp rather than zero. |
| SDK defects stay unfixed, leaving workaround code indefinitely | Medium | Low | Each workaround is named in the design record with what to delete once the fix lands. |
| Deliberate divergence from the PDK is later "corrected" by someone unaware of the reasoning | Medium | Medium | Each divergence and its rationale is recorded in TR-000005. |

## Approval

| Role | Name | Date | Status |
|---|---|---|---|
| Business Sponsor | | | Pending |
| Product Owner | | | Pending |

## Traceability

- **Implements:** —
- **Decomposed into Functional Requirements:**
  - [FR-000006 — Shipment export via SDK v11 shipment services](../functional-requirements/FR-000006-shipment-export-via-sdk-v11.md)
  - [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)
  - [FR-000008 — Carrier capabilities and contract definitions](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md)
  - [FR-000009 — Insurance as a range](../functional-requirements/FR-000009-insurance-as-a-range.md)
  - [FR-000010 — Graceful degradation on capability changes](../functional-requirements/FR-000010-graceful-degradation-on-capability-changes.md)
- **Technical Requirements:**
  - [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md)
  - [TR-000006 — Per-API-key export batching](../technical-requirements/TR-000006-per-api-key-export-batching.md)
  - [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md)
- **Design record:** [SDK v11 migration](../design/sdk-v11-migration.md)
