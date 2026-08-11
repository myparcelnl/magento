# FR-000009: Insurance as a Range

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000010](../user-stories/US-000010-admin-enters-insurance-amount-in-range.md)

## Description

Insurance changes from a fixed list of per-carrier amounts to **any amount within the account's contract minimum and maximum**.

Today the module offers insurance as a select list, populated per carrier and destination zone from a hardcoded tier list that lived on the SDK's consignment classes. The API does not work that way and never did: it exposes insurance as `min`, `max` and `default`, and it **accepts any value inside that range** — verified directly against the API. The tier lists were a client-side convenience with no meaning to the API, and they were the same for every merchant regardless of contract.

Required behaviour:

1. **The admin insurance setting becomes a numeric amount**, validated against the account's `[min, max]` for that carrier and zone, replacing the select list.
2. **Bounds come from the account.** The admin configuration screen reads them from the account's contract definitions; a concrete shipment reads them from that shipment's capabilities. Both yield `min`, `max` and `default`.
3. **Existing saved values stay valid.** Every amount currently saved was chosen from a tier inside the contract range, so it remains a legal value. The new domain is a superset of the old one; no migration of stored values is required.
4. **An out-of-range value clamps.** If a stored or submitted amount falls outside the account's range — because the contract changed — it is clamped to the nearest bound. It must never be silently reset to zero, which would ship a parcel uninsured without telling anyone.
5. **Unresolvable bounds do not block insurance.** Per [FR-000010](FR-000010-graceful-degradation-on-capability-changes.md), if the bounds cannot be retrieved, the configured amount is used and the API decides. Insurance is not disabled just because we could not confirm its limits.
6. **Historical data migrations keep their frozen tiers.** `src/Setup/UpgradeData.php` migrates old configuration using the tier lists that existed at the time. Those stay as module constants so old installations upgrade deterministically. This is the only place tiers survive.

**Divergence from the PDK, deliberately.** `myparcelnl/pdk` synthesises a tier ladder from the range (`InsuranceTierMath`: minimum, then €100/€250/€500 floors, then €500 steps, then maximum) in order to keep a select list. The Magento module does not port this. The steps are arbitrary, they are not what the API models, keeping a second copy in sync adds no functional value, and a derived ladder is the riskier migration — a stored amount could fail to match any generated step, whereas free input keeps every stored amount valid.

## User Impact

**Magento shop admins** see a number field instead of a dropdown, and can insure for the amount they actually want rather than the nearest offered step. For a merchant whose typical order value sits between two tiers, this is the difference between under-insuring and over-paying.

**Merchants with non-standard contracts** get bounds matching their own agreement rather than a list assembled for a typical PostNL account.

**Cost of the change:** a select list is harder to enter wrongly than a number field. This is mitigated by validating against the account's real bounds and clamping rather than rejecting, so a wrong entry is corrected rather than blocking work. The change is user-visible, which is why it has its own FR rather than riding along inside the migration.

## Acceptance Criteria

- [ ] The insurance setting renders as a numeric field, not a select, at every place it is configurable — all 16 carrier-and-zone combinations currently backed by a source model.
- [ ] The field validates against the account's `min` and `max` for that carrier and zone, and states the permitted range to the admin.
- [ ] An amount inside the range saves and exports successfully, including amounts that were never offered as a tier.
- [ ] An amount outside the range clamps to the nearest bound and is never reset to zero.
- [ ] Existing saved insurance amounts remain valid after upgrade with no manual step and no data migration.
- [ ] Bounds are read from the flat `min` / `max` / `default` properties on the insurance option, not from the deprecated nested wrapper.
- [ ] Two stores on different accounts can enforce different ranges for the same carrier and zone.
- [ ] With bounds unresolvable, the configured amount is still sent and insurance is not silently disabled.
- [ ] For each carrier and zone, the enforced range contains every amount the old tier list offered. If an old top tier exceeds the contract maximum, that is reported as a finding rather than quietly clamped in the migration.
- [ ] `setup:upgrade` on a pre-migration database produces identical rows, using the frozen tier constants and no network call.
- [ ] The insurance amount written to a shipment sits inside the shipment options object, matching the v11 request shape.

## Priority

**Classification:** Must Have

**Justification:** The method that produced the tier lists is deleted at beta.31, so insurance configuration cannot work unchanged. Since a replacement is unavoidable, the range model is chosen over a synthesised ladder for the reasons above.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — how bounds are retrieved and cached from both sources.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — the removal of `getInsurancePossibilities()` and the deliberate PDK divergence.

### Notes

The 16 virtual types in `etc/di.xml` and the source model behind them exist only to populate tier dropdowns and are removed with them. Their `carrierName` and `type` string arguments (`postnl`, `upsstandard`, …; `local`, `BE`, `EU`, `ROW`) currently encode the carrier-and-zone matrix, so whatever replaces them must preserve that matrix — a bound is meaningless without knowing which carrier and zone it applies to.

## Dependencies

### Upstream (this FR depends on)

- [FR-000008 — Carrier capabilities and contract definitions](FR-000008-carrier-capabilities-and-contract-definitions.md) — supplies the bounds.

### Downstream (depends on this FR)

- [FR-000006 — Shipment export via SDK v11](FR-000006-shipment-export-via-sdk-v11.md) — writes the resulting amount into the shipment options.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Phase 5 of the [migration plan](../design/sdk-v11-migration.md).

Worth flagging to the PDK team while implementing: PDK currently reads the deprecated nested `insured_amount` wrapper rather than the flat properties, and that wrapper is slated for removal.
