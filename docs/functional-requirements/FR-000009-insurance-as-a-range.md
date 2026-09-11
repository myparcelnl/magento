# FR-000009: Insurance as a Range

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000010](../user-stories/US-000010-admin-enters-insurance-amount-in-range.md)

## Description

Insurance changes from a fixed list of per-carrier amounts to **any amount within the account's contract minimum and maximum**.

The module used to offer insurance as a select list from a hardcoded tier list. The API exposes insurance as `min`, `max` and `default` and **accepts any value inside that range**, verified against the API. The tiers were a client-side construct, the same for every merchant regardless of contract.

Required behaviour:

1. **The admin insurance setting becomes a numeric amount**, validated against the account's `[min, max]` **for that carrier**. Contract definitions carry no country, so the four zone fields (`local`, `BE`, `EU`, `ROW`) stay what they were: a merchant's own cap per destination zone. The permitted set is the range itself, plus zero when the contract's `is_required` says insurance is optional. A minimum above zero bounds what an insured parcel may be insured for; it does not make insurance compulsory. An amount between zero and the minimum is refused either way.
2. **Bounds come from the account**, answering two questions. The settings screen reads contract definitions, which bound the **carrier**; a concrete shipment reads that shipment's capabilities, which bound this **destination and package type**. The second is authoritative and is where clamping happens.
3. **Existing saved values stay valid.** Every saved tier value is inside the contract range, so no migration of stored values is needed.
4. **An out-of-range value clamps** to the nearest bound and is never reset to zero, which would ship a parcel uninsured without telling anyone.
5. **Unresolvable bounds do not block insurance.** Per [FR-000010](FR-000010-graceful-degradation-on-capability-changes.md), the configured amount is used and the API decides.
6. **Data migrations keep their frozen tiers.** `src/Setup/UpgradeData.php` migrates old configuration with the tier lists frozen as module constants, offline.

**Divergence from the PDK, deliberately.** `myparcelnl/pdk` synthesises a tier ladder from the range to keep a select list. The module does not port this: the steps are arbitrary, they are not what the API models, and a derived ladder is the riskier migration because a stored amount could fail to match any step.

## Acceptance Criteria

- [x] The insurance setting renders as a numeric field at every place it is configurable: **17** carrier-and-zone combinations. The GLS Belgium field was missing and is added (see Notes).
- [x] The field validates against the account's `min` and `max` for that carrier, and states the permitted range.
- [ ] An amount inside the range saves and exports successfully, including amounts that were never offered as a tier.
- [x] An amount outside the range clamps to the nearest bound and is never reset to zero.
- [x] Existing saved insurance amounts remain valid after upgrade with no manual step.
- [x] Bounds are read from the flat `min` / `max` / `default` properties on the insurance option, not the deprecated nested wrapper. A captured acceptance response holds the API to that shape.
- [x] Two stores on different accounts can enforce different ranges for the same carrier.
- [x] With bounds unresolvable, the configured amount is still sent and insurance is not disabled.
- [ ] For each carrier and zone, the enforced range contains every amount the old tier list offered. An old top tier above the contract maximum is reported as a finding, not quietly clamped. A manual check against a real account.
- [ ] The insurance amount written to a shipment sits inside the shipment options object, matching the v11 request shape.

## Priority

**Classification:** Must Have

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — how bounds are retrieved and cached from both sources.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — the removal of `getInsurancePossibilities()` and the PDK divergence.

### Notes

The tier virtual types in `etc/di.xml` and their source model existed only to populate dropdowns and are removed with them.

GLS had no Belgium insurance field because its Belgium tier list was empty, so a dropdown would have offered only `0`. With the bound coming from the account the field is meaningful and is added: 17 settings for 17 combinations, a new field on the GLS tab. UPS had no insurance defaults in `etc/config.xml` despite carrying a setting; added.

## Dependencies

### Upstream (this FR depends on)

- [FR-000008 — Carrier capabilities and contract definitions](FR-000008-carrier-capabilities-and-contract-definitions.md) — supplies the bounds.
- [FR-000010 — Graceful degradation on capability changes](FR-000010-graceful-degradation-on-capability-changes.md) — its fail-open rule keeps insurance enabled when bounds cannot be resolved.

### Downstream (depends on this FR)

- [FR-000006 — Shipment export via SDK v11](FR-000006-shipment-export-via-sdk-v11.md) — writes the amount into the shipment options it builds.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Design record: [SDK v11 migration](../design/sdk-v11-migration.md).

Worth flagging to the PDK team: PDK reads the deprecated wrapper rather than the flat properties. Detail in TR-000007.
