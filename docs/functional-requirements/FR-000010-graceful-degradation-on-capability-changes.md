# FR-000010: Graceful Degradation on Capability Changes

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md)

## Description

The module must keep working when the capability data it receives changes shape, contains values it does not recognise, or cannot be retrieved.

This is a first-class requirement because it is the difference between a MyParcel-side change being a non-event and being an outage. Sibling integrations break when capability data changes because they treat it as an allow-list, so an option that is added, renamed or dropped becomes a crash or a silently missing feature.

**The governing rule:** the API is the validator. Capability data informs what the module *offers*; it never blocks what the module *sends*.

Required behaviour:

1. **Fail open.** If a lookup errors, times out or returns an unparseable shape, the module logs it and continues with permissive defaults. A capability failure never prevents a label being created.
2. **Serve stale over nothing.** If a refresh fails and previously retrieved data exists, the stale data is used.
3. **Never gate the export path on capability data.** Options from stored checkout data, bulk-action parameters or the REST API are sent even when current capability data does not list them. The one local stop is a value the SDK cannot serialize at all: a non-numeric package or delivery type with no id. That is the absence of anything to send, not a capability judgement.
4. **Unknown values pass through and are logged.** An unrecognised option key, carrier, package type or delivery type in a response is ignored where it cannot be used and recorded in the log. It raises no error and does not disappear without trace.
5. **Read defensively.** No code assumes a key exists in a response. Every read is null-safe; iteration is over what the response contains.
6. **Degrade, do not disappear.** Where capability data cannot say whether an option is available, the module offers it and lets the API decide. An unknown *numeric* type is sendable and sent; only a non-numeric one fails before the call.
7. **Outbound values are still validated.** Values the module constructs are validated before sending: read leniently, write strictly.

**Divergence from the PDK, deliberately.** `myparcelnl/pdk` filters capability responses against an allow-list of recognised carriers, types and options. The module does not: the allow-list is the mechanism that turns an upstream addition into a local breakage.

**Where the two sides meet.** A stored package type is never overridden on the way out; the age check used to do that silently. Instead the checkout does not offer a package type that capabilities say cannot carry an option the order forces on. That informs what is *offered*, which rule 3 permits, and the export sends what was stored.

**The accepted cost.** Offering an option the account cannot use produces an API error at export rather than a greyed-out control. That is the intended trade, but it only holds if the API's error reaches the admin legibly. Swallowing or flattening that error breaks the approach.

## Acceptance Criteria

- [ ] With the capabilities endpoint returning HTTP 500, creating a shipment still succeeds and a label is produced.
- [ ] With the capabilities endpoint timing out, the admin *New Shipment* form still renders and is usable.
- [ ] With a refresh failing and cached data present, the cached data is used.
- [ ] A response containing an unknown option key renders the form without error, and the key appears in the log.
- [ ] A response containing an unknown carrier, package type or delivery type raises no error; the value is logged.
- [ ] A response missing an option key the module reads raises no error.
- [ ] A shipment option present in stored checkout data but absent from current capability data is still sent.
- [ ] An option supplied through a bulk-action parameter is still sent, whether or not capability data lists it.
- [ ] An API rejection caused by an unavailable option is shown to the admin with the API's own message, identifying the order.
- [ ] An enum value the module constructs is validated before sending, so a module bug produces a local error rather than a malformed request.
- [ ] No code path treats capability data as an allow-list on the outbound side.
- [x] **No stored value is ever replaced by a different one.** A package or delivery type read from an order that cannot be resolved to an API value fails that shipment with a message naming the order and the value. It is never exported as the default.
- [ ] Such a failure stops only its own shipment; the other orders in the batch still export.
- [ ] Where a read path must return a value to keep a page rendering, the substitution is logged with the unresolved value. Rendering a default in place of a value the customer chose is never silent.
- [ ] Falling back because nothing was stored does not log. Only a value that was present and unrecognised does.
- [ ] **An unrecognised package or delivery type is displayed as itself** wherever a type is shown to an admin, never as a known type it is not.
- [ ] **An unrecognised numeric type is still sent.** The SDK serializes an unknown id unchanged, so the order stays exportable up to the API call and fails there with the API's own message.
- [ ] **An unrecognised non-numeric type fails before the call**, naming the value, because no id can be derived. Per shipment, not per batch.

## Priority

**Classification:** Must Have

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — the concrete rules, the fallback behaviour and the unknown-value logging.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — records the PDK divergence and its reasoning.

### Notes

The SDK supports this direction: `Support\EnumFallback` passes unknown enum values through unchanged on the read path while request serialization stays strict, the same asymmetry as rule 7. Its request mapper for capabilities deliberately does not filter enum-like values, "to preserve forward compatibility when the API adds new allowable values".

## Dependencies

### Upstream (this FR depends on)

- [FR-000008 — Carrier capabilities and contract definitions](FR-000008-carrier-capabilities-and-contract-definitions.md) — supplies the data this FR constrains.
- SDK `Support\EnumFallback` (beta.29 or later).

### Downstream (depends on this FR)

- [FR-000009 — Insurance as a range](FR-000009-insurance-as-a-range.md) — unresolvable bounds must not disable insurance.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Design record: [SDK v11 migration](../design/sdk-v11-migration.md).

This requirement is easy to erode by accident. A later change that adds "helpful" validation against capability data, or aligns the module with the PDK's filtering, silently reverses it. TR-000005 records the reasoning so a future reader meets the absent filter as a decision, not an omission.

Test it by injecting failure: a stubbed client returning HTTP 500, one timing out, one returning an extra unknown option, one returning a response missing an expected key. Each leaves the module working.
