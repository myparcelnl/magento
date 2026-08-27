# FR-000009: Insurance as a Range

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000010](../user-stories/US-000010-admin-enters-insurance-amount-in-range.md)

## Description

Insurance changes from a fixed list of per-carrier amounts to **any amount within the account's contract minimum and maximum**.

Today the module offers insurance as a select list, populated per carrier and destination zone from a hardcoded tier list that lived on the SDK's consignment classes. The API does not work that way and never did: it exposes insurance as `min`, `max` and `default`, and it **accepts any value inside that range** — verified directly against the API. The tier lists were a client-side convenience with no meaning to the API, and they were the same for every merchant regardless of contract.

Required behaviour:

1. **The admin insurance setting becomes a numeric amount**, validated against the account's `[min, max]` ~~for that carrier and zone~~ **for that carrier**, replacing the select list. The permitted set is the range itself, plus zero when the contract's `is_required` says insurance is optional. A minimum above zero bounds what an insured parcel may be insured for; it does not by itself make insurance compulsory. An amount between zero and the minimum is refused either way. Amended by DR-19: contract definitions carry no country, so the settings screen has no per-zone bound to validate against and the four zone fields stay what they always were — a merchant's own cap per destination zone.
2. **Bounds come from the account**, and they answer two different questions. The admin configuration screen reads the account's contract definitions, which bound the **carrier**; a concrete shipment reads that shipment's capabilities, which bound this **destination and package type**. The second is the authoritative one and is where clamping happens. Both yield `min`, `max` and `default`.
3. **Existing saved values stay valid.** Every amount currently saved was chosen from a tier inside the contract range, so it remains a legal value. The new domain is a superset of the old one; no migration of stored values is required.
4. **An out-of-range value clamps.** If a stored or submitted amount falls outside the account's range — because the contract changed — it is clamped to the nearest bound. It must never be silently reset to zero, which would ship a parcel uninsured without telling anyone.
5. **Unresolvable bounds do not block insurance.** Per [FR-000010](FR-000010-graceful-degradation-on-capability-changes.md), if the bounds cannot be retrieved, the configured amount is used and the API decides. Insurance is not disabled just because we could not confirm its limits.
6. **Historical data migrations keep their frozen tiers.** `src/Setup/UpgradeData.php` migrates old configuration using the tier lists that existed at the time, frozen as module constants. This is the only place tiers survive; the offline constraint is in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

**Divergence from the PDK, deliberately.** `myparcelnl/pdk` synthesises a tier ladder from the range (`InsuranceTierMath`: minimum, then €100/€250/€500 floors, then €500 steps, then maximum) in order to keep a select list. The Magento module does not port this. The steps are arbitrary, they are not what the API models, keeping a second copy in sync adds no functional value, and a derived ladder is the riskier migration — a stored amount could fail to match any generated step, whereas free input keeps every stored amount valid.

## User Impact

**Magento shop admins** see a number field instead of a dropdown, and can insure for the amount they actually want rather than the nearest offered step. For a merchant whose typical order value sits between two tiers, this is the difference between under-insuring and over-paying.

**Merchants with non-standard contracts** get bounds matching their own agreement rather than a list assembled for a typical PostNL account.

**Cost of the change:** a select list is harder to enter wrongly than a number field. This is mitigated by validating against the account's real bounds and clamping rather than rejecting, so a wrong entry is corrected rather than blocking work. The change is user-visible, which is why it has its own FR rather than riding along inside the migration.

## Acceptance Criteria

- [x] The insurance setting renders as a numeric field, not a select, at every place it is configurable — **17** carrier-and-zone combinations, not 16: the GLS Belgium field was the missing setting the orphaned virtual type pointed at (see Notes).
- [x] The field validates against the account's `min` and `max` for that carrier, and states the permitted range to the admin.
- [ ] An amount inside the range saves and exports successfully, including amounts that were never offered as a tier. **Saving is verified in Phase 5; exporting is verified in Phase 6** — DR-20's shim still snaps a domestic amount to a tier on the pinned SDK.
- [x] An amount outside the range clamps to the nearest bound and is never reset to zero.
- [x] Existing saved insurance amounts remain valid after upgrade with no manual step and no data migration.
- [x] Bounds are read from the flat `min` / `max` / `default` properties on the insurance option. The deprecated nested wrapper is not read, and a captured acceptance response holds the API to that shape.
- [x] Two stores on different accounts can enforce different ranges for the same carrier.
- [x] With bounds unresolvable, the configured amount is still sent and insurance is not silently disabled.
- [ ] For each carrier and zone, the enforced range contains every amount the old tier list offered. If an old top tier exceeds the contract maximum, that is reported as a finding rather than quietly clamped in the migration. **A manual check against a real account, not a test.**
- [ ] The insurance amount written to a shipment sits inside the shipment options object, matching the v11 request shape. **Phase 6.**

## Priority

**Classification:** Must Have

**Justification:** The method that produced the tier lists is deleted at beta.31, so insurance configuration cannot work unchanged. Since a replacement is unavoidable, the range model is chosen over a synthesised ladder for the reasons above.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — how bounds are retrieved and cached from both sources.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — the removal of `getInsurancePossibilities()` and the deliberate PDK divergence.

### Notes

The 17 virtual types in `etc/di.xml` and the source model behind them exist only to populate tier dropdowns and are removed with them. Only 16 are referenced by a setting, so one is already orphaned. Their `carrierName` and `type` string arguments (`postnl`, `upsstandard`, …; `local`, `BE`, `EU`, `ROW`) currently encode the carrier-and-zone matrix, so whatever replaces them must preserve that matrix.

**The orphan was a missing setting, and the reason it was missing is gone.** `\GLS\Belgium` had no
field in `etc/dynamic_settings.json`, but `etc/config.xml` carried a GLS `insurance_belgium_amount`
default with nothing to fill it. Running the beta.15 SDK explains the gap: GLS's Belgium tier list is
**empty**, so the dropdown would have offered nothing but `0`. With the bound now coming from the
account rather than a hardcoded list, the field is meaningful, so it was added — 17 settings for 17
combinations. Merchant-visible: a new field appears on the GLS tab.

A second gap found the same way: `myparcelnl_magento_ups_settings` had **no** insurance defaults in
`etc/config.xml` despite carrying an insurance setting. Added. The GLS defaults of `100` need no
change — `100` was never in the GLS tier list `[10000]`, so it rendered unselected; with free input it
is simply a legal value.

## Dependencies

### Upstream (this FR depends on)

- [FR-000008 — Carrier capabilities and contract definitions](FR-000008-carrier-capabilities-and-contract-definitions.md) — supplies the bounds.
- [FR-000010 — Graceful degradation on capability changes](FR-000010-graceful-degradation-on-capability-changes.md) — its fail-open rule is what keeps insurance enabled when the bounds cannot be resolved.

### Downstream (depends on this FR)

- [FR-000006 — Shipment export via SDK v11](FR-000006-shipment-export-via-sdk-v11.md) — consumes the amount and writes it into the shipment options it builds. Phase 5 therefore lands before Phase 6.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Phase 5 of the [migration plan](../design/sdk-v11-migration.md).

Worth flagging to the PDK team while implementing: PDK reads the deprecated wrapper rather than the flat properties. Detail in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).
