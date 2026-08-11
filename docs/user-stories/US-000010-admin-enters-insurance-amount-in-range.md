# US-000010: Admin Enters Any Insurance Amount Within Their Contract's Range

## Parent Functional Requirement

- **FR:** [FR-000009 — Insurance as a range](../functional-requirements/FR-000009-insurance-as-a-range.md)

## Story

As a **Magento shop admin**,
I want **to enter the insurance amount I actually want, within the range my MyParcel contract allows**,
So that **I stop having to round up to the next offered tier and over-pay, or round down and under-insure**.

## Acceptance Criteria

### Scenario 1: The field accepts a value that was never a tier

**Given** my contract allows insurance between €0 and €5000 for PostNL domestic,
**When** I enter €137 as the insurance amount and save,
**Then** the value is accepted,
**And** exporting a shipment with it succeeds and MyParcel records €137 insured.

### Scenario 2: The range is shown and enforced

**Given** my contract's maximum for a carrier and zone is €500,
**When I** open that insurance setting,
**Then** the permitted range is stated in the field's label or comment,
**And** entering €600 is rejected with a message naming the permitted range,
**And** no value outside the range is saved.

### Scenario 3: Existing saved amounts survive the upgrade

**Given** an installation upgraded from a version with tier dropdowns,
**And** insurance amounts were previously saved for several carriers and zones,
**When** I open the configuration after the upgrade,
**Then** each previously saved amount is still present and still valid,
**And** I am not asked to re-select anything.

### Scenario 4: An out-of-range stored value clamps, never zeroes

**Given** a stored amount of €5000 and a contract whose maximum has since dropped to €2500,
**When** a shipment is exported,
**Then** the amount used is €2500,
**And** it is not €0,
**And** the clamping is recorded in the log.

### Scenario 5: Two accounts enforce two different ranges

**Given** store A's account allows up to €5000 and store B's up to €500 for the same carrier and zone,
**When** I view the insurance setting at each store's scope,
**Then** each shows and enforces its own account's range.

### Scenario 6: Unresolvable bounds do not disable insurance

**Given** the contract definitions cannot be retrieved,
**When** I export a shipment with insurance configured,
**Then** the configured amount is still sent,
**And** insurance is not silently switched off,
**And** the API decides whether the amount is acceptable.

### Scenario 7: Old top tiers exceeding the contract maximum are surfaced

**Given** the previously hardcoded tier list offered an amount above what my contract actually allows,
**When** the migration is verified for that carrier and zone,
**Then** the discrepancy is reported as a finding rather than quietly clamped,
**So that** it can be checked with MyParcel rather than assumed to be a rounding difference.

## Story Points

**Estimate:** 5
**Complexity:** Medium

## Technical Notes

- Bounds come from two sources depending on context, both giving flat `min` / `max` / `default`: shipment capabilities for a concrete shipment, contract definitions for the admin screen. See [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).
- Read the **flat** properties, not the deprecated nested `insured_amount` wrapper. `myparcelnl/pdk` still reads the wrapper; that wrapper is slated for removal.
- The 16 virtual types in `etc/di.xml` and their source model exist only to populate tier dropdowns and are removed. Their `carrierName` and `type` arguments encode the carrier-and-zone matrix, which must be preserved in whatever replaces them — a bound is meaningless without knowing which carrier and zone it applies to.
- Scenario 3 works because the new domain is a superset of the old: every tier value was inside the contract range. No data migration is needed, which is the main argument for free input over a synthesised ladder.
- `src/Setup/UpgradeData.php` keeps frozen tier constants for historical migrations and must not make a network call.

## Dependencies

- [FR-000008](../functional-requirements/FR-000008-carrier-capabilities-and-contract-definitions.md) must supply the bounds before this story can be implemented.

## Definition of Done

- [ ] All seven scenarios verified, using two accounts with different contracts for scenario 5.
- [ ] Unit tests cover clamping, rejection of out-of-range input, and the unresolvable-bounds fallback.
- [ ] Upgrade tested from a pre-migration database snapshot; saved amounts intact and no re-import required.
- [ ] Old tier lists compared against contract ranges per carrier and zone; any exceedance recorded as a finding.
- [ ] `setup:upgrade` verified offline.
- [ ] Documentation updated (this US, FR-000009, TR-000007).
