# FR-000008: Carrier Capabilities and Contract Definitions

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000010](../user-stories/US-000010-admin-enters-insurance-amount-in-range.md)

## Description

The module must obtain carrier capability data — package types, delivery types, shipment options, the collo maximum and the insurance bounds — from the MyParcel API rather than from hardcoded values, **per MyParcel account**.

The module used to answer these questions by interrogating a throwaway consignment (`canHaveShipmentOption()`, `getAllowedPackageTypes()`, `getInsurancePossibilities()` and the like). Those answers were the same for every merchant. They are contract data and differ per account. Two sources replace them, distinguished by whether a concrete shipment exists:

| Question | Source |
|---|---|
| What may *this* shipment have? (country, weight, package type, delivery type known) | Capabilities endpoint, per account |
| What does this account's contract allow at all? (admin configuration, no shipment in hand) | Contract definitions, per account |

Required behaviour:

1. **Account-scoped.** Every lookup is made with the API key of the relevant store.
2. **The admin form reflects the account.** The *New Shipment* form offers the package types, delivery types and options the account has, per carrier.
3. **Checkout reflects the account.** Delivery options offered at checkout are consistent with the store's capabilities.
4. **Multicollo eligibility comes from the API**, from the reported collo maximum, replacing the hardcoded PostNL, NL/BE, `package` rule.
5. **Country-zone logic is not capability data.** Whether a destination is outside the EU, and a carrier's local country, are static facts sourced from `Sdk\Services\CountryCodes`, never network calls.
6. **Data migrations do not call the network.** `src/Setup/UpgradeData.php` uses frozen tier constants, so upgrading works offline. Constraint in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).
7. **Lookups must not make the module slow.** They are needed at checkout and on every admin form render, so caching is a requirement; see TR-000007.

Resilience to capability changes is [FR-000010](FR-000010-graceful-degradation-on-capability-changes.md): capability data informs what is offered and never blocks an export.

## Acceptance Criteria

- [ ] Package types, delivery types and shipment options on the admin *New Shipment* form come from the account's capability data, per carrier.
- [ ] For an account whose contract matches the old hardcoded assumptions, the rendered options are identical to beta.15. Any difference is explainable from that account's contract.
- [ ] Two stores with different API keys can render different option sets on the same form.
- [ ] Multicollo eligibility is decided by the reported collo maximum.
- [ ] Insurance bounds (`min`, `max`, `default`) are read per account; FR-000009 says how they are used.
- [ ] Checkout delivery options remain consistent with the store's capabilities and unchanged in shape.
- [ ] Whether a destination is outside the EU, and a carrier's local country code, are resolved without a network call.
- [ ] `setup:upgrade` on a pre-migration database produces identical rows with no network access.
- [ ] A cold checkout makes at most one capability call per distinct account and request shape; a warm one makes none.
- [ ] Rendering the admin *New Shipment* form does not make one uncached call per carrier on every page load.

## Priority

**Classification:** Must Have

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — the client, the V2 response format, the two-tier storage split, cache keys and invalidation.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — which removed methods map to which source.

### Notes

The SDK's own `Services\Capabilities\CapabilitiesService` cannot be used as shipped: it accepts no API key, calls the generated client with the wrong argument order, and its response mapper discards every per-option value including insurance bounds. The issues are reported upstream; the module calls the endpoint directly. Detail in TR-000007.

`myparcelnl/pdk` solved this first and is the reference implementation. It carries one requirement documented nowhere else, the `version=2` `Accept` header, specified in TR-000007.

## Dependencies

### Upstream (this FR depends on)

- The MyParcel Core API capabilities and contract-definitions endpoints.
- `MyParcelNL\Magento\Service\Hash\Fingerprint` for cache keys, landed in #967.

### Downstream (depends on this FR)

- [FR-000009 — Insurance as a range](FR-000009-insurance-as-a-range.md) — consumes the insurance bounds.
- [FR-000010 — Graceful degradation on capability changes](FR-000010-graceful-degradation-on-capability-changes.md) — constrains how this data may be used.
- [FR-000006 — Shipment export via SDK v11](FR-000006-shipment-export-via-sdk-v11.md) — multicollo eligibility.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Design record: [SDK v11 migration](../design/sdk-v11-migration.md).

Carrier-specific behaviour is tested against a **stubbed capability response**, never against a live account and never against today's PostNL values. The tests assert the mapping from capability data to behaviour, so they survive contract changes.
