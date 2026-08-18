# BR-000003: MyParcel Magento Module Runs on MyParcel SDK v11

## Business Context

The Magento module is an adapter over the MyParcel PHP SDK (`myparcelnl/sdk`). The SDK's v11 line replaced its hand-written consignment stack with generated API clients and typed service wrappers, and in **beta.22** it deleted the legacy stack outright. The module is pinned to `11.0.0-beta.15@beta` because beta.22 and every release after it removes classes the module depends on.

That pin is not a stable resting place. It is a snapshot of a moving beta, held in place by incompatibility rather than by choice. While it holds:

- The module cannot adopt anything the SDK has added since beta.15 — the capabilities endpoint, contract definitions, the webhook service, API key validation.
- It cannot receive SDK bug fixes or generated-client regenerations, which is how new carriers, package types and shipment options reach integrations.
- It diverges further from `myparcelnl/pdk`, which already runs on beta.30, so the two integrations no longer share a foundation.

Separately, the SDK's removal of `MyParcelCollection` removed a capability the module relied on without owning: **exporting shipments for several MyParcel accounts in one action**. beta.15's collection grouped consignments by API key internally and split the API calls per key. Nothing in v11 does that; the API key is now a constructor argument on each `final` service, so batching by account is the consumer's responsibility.

## Objective

The module runs on `myparcelnl/sdk` **v11.0.0-beta.31**, with multi-account batch export re-implemented inside the module, and with one deliberate capability removal: pre-export address validation, for the reasons below.

A merchant running several Magento stores against several MyParcel accounts must be able to select orders across those stores in one admin action and receive one merged label PDF, exactly as they can today.

## Business Justification

- **Removing a release blocker.** The pin blocks every future SDK adoption. Each further beta widens the gap and increases the eventual migration cost; the breaking change is a single cliff at beta.22, so the cost does not reduce by waiting.
- **Carrier and option changes reach merchants.** Package types, delivery types, shipment options and carriers are contract data that changes on the MyParcel side. On v11 they arrive from the capabilities endpoint per account. On beta.15 they are hardcoded in deleted SDK classes, so a new carrier option requires a module release.
- **Per-account correctness for multi-store merchants.** This is not a new feature but a regression risk. If multi-account batching is not re-implemented, a merchant with two MyParcel accounts silently ships one account's parcels against the other's contract, or loses the ability to batch at all.
- **Convergence with the PDK.** Sharing an SDK major with `myparcelnl/pdk` means carrier changes are validated once rather than per integration.

## Scope

### In Scope

- Compatibility with SDK v11.0.0-beta.31: shipment creation, label retrieval, track & trace, return shipments, multicollo, and the fulfilment (PPS) export path.
- **Multi-account batch export**, re-implemented in the module: grouping by API key resolved from each order's store, one API client per key, and merging the per-account label PDFs into a single download.
- **Capability data sourced from the API** — allowed package types, delivery types, shipment options, collo maximum and insurance bounds — replacing values previously hardcoded in the SDK's consignment classes.
- **Insurance as a range.** Insurance becomes any amount within the account's contract minimum and maximum, replacing the fixed per-carrier tier lists. This is a user-visible admin change and an increase in merchant capability.
- **Graceful degradation.** The module keeps exporting when the capabilities response changes shape, contains unknown values, or is unavailable.
- **A configurable export chunk size** (default 20), because large single batches time out in practice.
- **Pre-export address validation is removed**, not ported. `Sdk\Helper\ValidatePostalCode` is deleted at beta.22 and `Helper\ValidateStreet` survives, so the module could have kept half of the check. Keeping half is worse than keeping none: a bad street is named before export while a bad postcode is found only when the API rejects it. The API becomes the single authority on address validity. Merchants lose the "⚠️ Please check street" and "Please check postal code" warnings in the order grid, and a malformed address no longer drops its order out of a mass action with a named message. Rationale in DR-11 of the migration plan.
- Six pre-existing defects in the same area, four of them multi-store: cron PPS status polling reaching only one account; return labels created against the wrong account in a mixed batch; PPS order lines accumulating across orders in one batch; a repeated concept mass action creating duplicate billable shipments; customs items added twice on some paths; and the age-check precedence chain whose lower two tiers are unreachable.

### Out of Scope

- **Upgrading past beta.31.** The target is a specific tag. Tracking the beta line thereafter is ordinary maintenance.
- **Fixing the SDK.** Three defects found during investigation are reported as issues on `myparcelnl/sdk` in Phase 4 and worked around in the module. We do not open SDK pull requests as part of this work.
- **Any change to the checkout delivery-options widget contract**, the versioned REST API response shape, or the admin configuration structure beyond the insurance field.
- **Retiring the legacy `@internal` SDK classes** the module still uses (`AccountWebService`, `CarrierOptionsWebService`, `OrderCollection`). They work at beta.31; replacing them is separate work.
- **Hashing the API key out of the `account_settings_{apiKey}` config path and cleaning up orphaned rows.** Related and lands first, but a separate pull request with its own rationale.

## Success Criteria

- [ ] `composer.json` requires `myparcelnl/sdk: 11.0.0-beta.31@beta`, and `setup:di:compile`, `setup:upgrade` and the Pest suite all pass on PHP 8.1 through 8.4.
- [ ] Every export path that works on beta.15 works on beta.31: order-view shipment creation, both admin grid mass actions, create-concept-after-invoice, the return-label mail action, the status cron, and PPS/fulfilment export mode.
- [ ] A single admin mass action spanning orders from two Magento stores with **two different MyParcel API keys** creates each shipment in its correct MyParcel account and returns **one merged PDF** containing all labels.
- [ ] A batch larger than the configured chunk size completes without timing out, and a failure in one chunk leaves the shipments created by earlier chunks recorded against their Magento orders rather than orphaned in MyParcel.
- [ ] The admin *New Shipment* form offers the same package types, delivery types and shipment options as on beta.15 for every supported carrier, now sourced from the account's capabilities rather than from hardcoded values.
- [ ] An admin can enter any insurance amount within their contract's range and export successfully with it, including amounts that were not previously offered as a tier.
- [ ] A capabilities response containing an option, carrier or package type the module does not recognise does not break the admin form, the checkout, or an export. The unknown value is logged.
- [ ] With the capabilities endpoint unreachable, label creation still succeeds.
- [ ] The status cron updates orders across **all** configured MyParcel accounts, not only the default-scope one.
- [ ] An order with a malformed street or postcode is accepted, shows no warning in the order grid, and surfaces the API's own rejection legibly at export.
- [ ] No merchant-visible configuration is lost or silently reset by the upgrade; existing saved insurance amounts remain valid.

## Stakeholders

| Role | Name | Responsibility |
|---|---|---|
| Business Sponsor | MyParcel External Integrations team | Funds and prioritises this work |
| Product Owner | MyParcel platform PM | Accepts the insurance UI change and the degradation behaviour |
| Technical Lead | MyParcel Magento module maintainer | Feasibility, phasing, design decisions (see the migration plan) |
| Supplier | MyParcel PHP SDK team | Owns the three reported defects and the capabilities contract |
| End Users | MyParcel customers (Magento shop admins), especially multi-store merchants | Validation |

## Constraints

- **Technical:** The module's PHP floor stays `^7.4 || ^8.0`; beta.31 does not raise it. No `vendor/**` file may be modified. The generated SDK client requires `guzzlehttp/guzzle ^7.10`, which the target Magento versions already satisfy.
- **Delivery:** The module is broken against beta.31 until the migration completes, so this is one deliverable rather than a series of independently releasable increments. Intermediate commits remain runnable against beta.15–beta.21, where both SDK stacks coexist, which is what makes the work reviewable in steps.
- **Testing:** Live verification uses `*.acceptance.myparcel.nl` credentials only. Production MyParcel endpoints must not be used for testing.
- **Compatibility:** Existing merchant configuration must survive the upgrade without a manual re-import step.

## Dependencies

- SDK tag `v11.0.0-beta.31` and its `UPGRADE.md`.
- The MyParcel Core API capabilities and contract-definitions endpoints, and their `version=2` response format.
- `myparcelnl/pdk` v4.7.1 as a reference implementation for the capabilities client and the storage split.
- The API-key hashing and orphan-cleanup pull request, which lands first.

## Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Capability parity gap: the capabilities endpoint cannot answer something a consignment class used to | Medium | High | The PDK proves the endpoint covers the questions we ask. Remaining gaps are raised as questions and proceed on a documented assumption recorded in TR-000005, rather than blocking a phase. |
| Silent behaviour drift during a large refactor with no existing test coverage | High | High | Phase 1 pins our own decision rules in hand-written tests before any refactoring, and Phase 6 requires those tests to pass unchanged. Requiring a test edit is treated as a behaviour change needing justification. |
| A future capabilities change breaks the module, as has happened with sibling integrations | Medium | High | FR-000010: capabilities inform the UI but never gate an export; unknown values are logged and passed through; the module fails open when capabilities are unavailable. |
| Multi-account batching implemented incorrectly, shipping parcels against the wrong contract | Medium | High | TR-000006 makes grouping explicit and one client per key mandatory. Unit tests assert N keys produce N create calls; the two-store manual test is a release gate. |
| A mid-batch failure leaves shipments in MyParcel with no Magento reference | Medium | Medium | Per-chunk persistence before the next chunk is issued, plus a per-chunk success/failure report to the admin. |
| The insurance field change confuses admins or invalidates saved values | Low | Low | The new domain is a superset of the old, so saved tier values stay valid. Out-of-range values clamp rather than zero. Covered by FR-000009 and US-000010. |
| Three SDK defects remain unfixed, leaving workaround code in place indefinitely | Medium | Low | Workarounds carry a `@todo` referencing the issue, and TR-000007 records what to delete once fixed. Filing them is Phase 4 work, so the `@todo` numbers land with that phase. |
| Divergence from the PDK on three deliberate points is later "corrected" by someone unaware of the reasoning | Medium | Medium | Each divergence is recorded with its rationale in TR-000005 and in the migration plan's decision records. |

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
- **Implementation plan:** [SDK v11 migration plan](../design/sdk-v11-migration.md)
