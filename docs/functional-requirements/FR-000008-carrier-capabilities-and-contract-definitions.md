# FR-000008: Carrier Capabilities and Contract Definitions

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000010](../user-stories/US-000010-admin-enters-insurance-amount-in-range.md)

## Description

The module must obtain carrier capability data — which package types, delivery types and shipment options are available, the maximum collo count, and the insurance bounds — from the MyParcel API rather than from hardcoded values, and it must do so **per MyParcel account**.

Today the module answers these questions by constructing a throwaway consignment and interrogating it: `canHaveShipmentOption()`, `canHaveDeliveryType()`, `canHavePackageType()`, `canHaveExtraOption()`, `getAllowedPackageTypes()`, `getAllowedShipmentOptions()`, `getInsurancePossibilities()`, `getLocalCountryCode()`, `isToRowCountry()`. Those methods lived on SDK consignment classes that beta.22 deleted, and their answers were the same for every merchant.

They are not the same for every merchant. Available carriers, options and insurance bounds are **contract data**, differing per MyParcel account. Two sources replace them, distinguished by whether a concrete shipment exists:

| Question being asked | Source |
|---|---|
| What may *this* shipment have? (country, weight, package type, delivery type known) | Capabilities endpoint, per account |
| What does this account's contract allow at all? (admin configuration, no shipment in hand) | Contract definitions, per account |

Required behaviour:

1. **Account-scoped.** Every capability lookup is made with the API key of the relevant store. Two stores on different accounts may legitimately get different answers for the same shipment.
2. **The admin form reflects the account.** The *New Shipment* form offers the package types, delivery types and shipment options the account actually has, per carrier.
3. **Checkout reflects the account.** Delivery options offered at checkout are consistent with the store's own capabilities.
4. **Multicollo eligibility comes from the API.** The current hardcoded rule — PostNL, NL or BE, package type `package` — is replaced by the collo maximum the capabilities response reports.
5. **Country-zone logic is not capability data.** Whether a destination is outside the EU, and what a carrier's local country is, are static facts. They stay in the module, sourced from `Sdk\Services\CountryCodes`, and must not become network calls.
6. **Historical data migrations do not call the network.** `src/Setup/UpgradeData.php` currently derives insurance tiers via a consignment probe during upgrade. It must use frozen constants instead, so upgrading an old installation is deterministic and works offline. Constraint in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).
7. **Capability lookups must not make the module slow.** These answers are currently computed in-process with no I/O, and they are needed on checkout and on every admin form render. Caching is a requirement, not an optimisation; see [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

Resilience to capability changes is specified separately in [FR-000010](FR-000010-graceful-degradation-on-capability-changes.md), which this FR must not contradict: capability data informs what is offered, but never blocks an export.

## User Impact

**Merchants with non-standard contracts** stop seeing options they cannot use and start seeing options they can. Today the module shows one hardcoded set to everyone, so a merchant with an unusual contract sees choices that fail at the API, and misses ones they are paying for.

**Merchants on new carriers or options** get them without waiting for a module release, because the option list now comes from their account rather than from module code.

**All merchants** are exposed to a new failure mode — a network call where there was none — which is why caching and the degradation rules in FR-000010 are part of the requirement rather than follow-up work.

## Acceptance Criteria

- [ ] Allowed package types, delivery types and shipment options rendered on the admin *New Shipment* form come from the account's capability data, per carrier.
- [ ] For an account whose contract matches today's hardcoded assumptions, the rendered options are identical to beta.15. Any difference is explainable from that account's contract.
- [ ] Two stores configured with different API keys can render different option sets on the same form, each matching its own account.
- [ ] Multicollo eligibility is decided by the reported collo maximum, not by a hardcoded carrier, country and package-type rule.
- [ ] Insurance bounds (`min`, `max`, `default`) are read per account; see FR-000009 for how they are then used.
- [ ] Checkout delivery options remain consistent with the store's capabilities and are unchanged in shape from today.
- [ ] Whether a destination is outside the EU, and a carrier's local country code, are resolved without a network call.
- [ ] `setup:upgrade` on a pre-migration database produces identical rows with no network access available. This is the one offline-upgrade criterion for the set; FR-000009 states the frozen-tier rule that makes it hold.
- [ ] A cold checkout makes at most one capability call per distinct account and request shape; a warm one makes none.
- [ ] Rendering the admin *New Shipment* form does not make one uncached call per carrier on every page load.

## Priority

**Classification:** Must Have

**Justification:** The methods this replaces are deleted at beta.31, so the admin form and checkout cannot function without it. It is also the phase that turns hardcoded assumptions into per-account truth, which is the main merchant-facing benefit of the upgrade.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — the client, the V2 response format, the two-tier storage split, cache keys and invalidation.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — which removed methods map to which source.

### Notes

The SDK's own `Services\Capabilities\CapabilitiesService` cannot be used as shipped: it accepts no API key, calls the generated client with the wrong argument order, and its response mapper discards every per-option value including insurance bounds. Three issues are reported upstream in Phase 4; the module calls the generated endpoint directly regardless. Full detail and the retirement path are in TR-000007.

`myparcelnl/pdk` v4.7.1 has already solved this and is the reference implementation. It also carries one requirement documented nowhere else — the explicit `version=2` `Accept` header, specified in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

## Dependencies

### Upstream (this FR depends on)

- The MyParcel Core API capabilities and contract-definitions endpoints.
- The prerequisite pull request's shared hash helper, reused for cache keys.

### Downstream (depends on this FR)

- [FR-000009 — Insurance as a range](FR-000009-insurance-as-a-range.md) — consumes the insurance bounds.
- [FR-000010 — Graceful degradation on capability changes](FR-000010-graceful-degradation-on-capability-changes.md) — constrains how this data may be used.
- [FR-000006 — Shipment export via SDK v11](FR-000006-shipment-export-via-sdk-v11.md) — multicollo eligibility.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Phase 4 of the [migration plan](../design/sdk-v11-migration.md), with the contract-definitions and account-settings half in Phase 5.

Phase 4 is the largest phase in the plan and is expected to split during execution. The natural seams are the client and cache, the admin form, the checkout probes, and the DI cleanup.

Carrier-specific behaviour must be tested against a **stubbed capability response**, not against a live account and not against today's PostNL values. The tests assert the mapping from capability data to behaviour, so they survive contract changes; asserting today's carrier facts would defeat the purpose of the change.
