# FR-000010: Graceful Degradation on Capability Changes

## Parent Requirement

- **Business Requirement:** [BR-000003 — MyParcel Magento module runs on MyParcel SDK v11](../business-requirements/BR-000003-sdk-v11-compatibility.md)
- **Related User Stories:** [US-000009](../user-stories/US-000009-admin-gets-per-order-export-report.md)

## Description

The module must keep working when the capability data it receives changes shape, contains values it does not recognise, or cannot be retrieved at all.

This is a first-class requirement rather than an implementation detail, because it is the difference between a MyParcel-side change being a non-event for merchants and being an outage. Sibling integrations have a history of breaking when capability data changes, and the mechanism is consistent: the integration treats capability data as an allow-list, so an option that is added, renamed, or stops being returned becomes either a crash or a silently missing feature.

**The governing rule:** the API is the validator. Capability data informs what the module *offers*; it never blocks what the module *sends*.

Required behaviour:

1. **Fail open.** If a capability lookup errors, times out, or returns an unparseable shape, the module logs it and continues with permissive defaults — offering what configuration allows. A capability failure must never prevent a label being created.
2. **Serve stale over nothing.** If a refresh fails but previously retrieved data is available, the stale data is used.
3. **Never gate the export path on capability data.** Options arriving from stored checkout data, bulk-action parameters or the REST API are passed through to the shipment even when current capability data does not list them. There is no equivalent of the old `canHaveShipmentOption()` gate on the way out. The one thing that does stop a shipment locally is a value the SDK cannot serialize at all — a non-numeric package or delivery type for which no id exists (criteria below). That is not a capability judgement; it is the absence of anything to send.
4. **Unknown values pass through and are logged.** An unrecognised option key, carrier, package type or delivery type in a response is ignored where it cannot be used and recorded in the log. It must not raise an error, and it must not disappear without trace.
5. **Read defensively.** No code may assume a given option key exists in a response. Every read is null-safe; iteration is over what the response actually contains.
6. **Degrade, do not disappear.** Where capability data cannot tell us whether an option is available, the module offers it and lets the API decide, rather than hiding a feature the merchant may be paying for. An unknown *numeric* type is sendable at beta.31 and therefore sent; only a non-numeric one, which has no derivable id, fails before the call.
7. **Outbound values are still validated.** The tolerance above applies to reading responses. Values the module itself constructs are validated before sending — read leniently, write strictly, per [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

**Divergence from the PDK, deliberately.** `myparcelnl/pdk` filters capability responses against an allow-list of carriers, delivery types, package types and options it recognises, dropping the rest. The Magento module does not port that filter. The allow-list is the mechanism that turns an upstream addition into a local breakage, and this module prioritises staying up over presenting a curated set.

**The accepted cost.** Offering an option the account cannot actually use produces an API error at export time rather than a greyed-out control at configuration time. That is the intended trade — a clear late failure is better than a silent missing feature — but it only holds if the API's error reaches the admin legibly. Swallowing or flattening that error breaks the whole approach.

## User Impact

**All merchants** are protected from MyParcel-side changes. A new carrier option, a renamed field or a temporarily unavailable capabilities endpoint does not stop them shipping.

**Merchants during an incident** can still create labels when the capabilities endpoint is degraded, because label creation does not depend on it.

**MyParcel support** gets a log trail of unrecognised values, which turns "the plugin is broken" into "the plugin saw an option it did not know about, here it is". This is also an early-warning signal that the module needs updating.

## Acceptance Criteria

- [ ] With the capabilities endpoint returning HTTP 500, creating a shipment still succeeds and a label is still produced.
- [ ] With the capabilities endpoint timing out, the admin *New Shipment* form still renders and remains usable.
- [ ] With a refresh failing and previously cached data present, the cached data is used rather than falling through to defaults.
- [ ] A response containing an option key the module does not recognise renders the form without error, and the unknown key appears in the log.
- [ ] A response containing an unknown carrier, package type or delivery type does not raise an error; the value is logged.
- [ ] A response missing an option key the module does read does not raise an error.
- [ ] A shipment option present in stored checkout data but absent from current capability data is still sent to the API.
- [ ] An option supplied through a bulk-action parameter is still sent, whether or not capability data lists it.
- [ ] An API rejection caused by an unavailable option is surfaced to the admin with the API's own message, identifying the affected order, and is not replaced by a generic failure.
- [ ] An enum value the module itself constructs is validated before sending, so a module bug produces a local error rather than a malformed request.
- [ ] No code path treats capability data as an allow-list on the outbound side.
- [ ] **No stored value is ever replaced by a different one.** A package or delivery type read from an order's delivery options that cannot be resolved to an API value fails that shipment with a message naming the order and the unresolved value. It is never exported as the default, and never as anything other than what was stored.
- [ ] A failure of that kind stops only its own shipment; the other orders in the batch still export, and the admin is told which succeeded.
- [ ] Where a read path must return a value to keep a page rendering — an admin form pre-selecting a delivery type, a default package type — the substitution is logged with the unresolved value. Rendering a default in place of a value the customer chose is never silent.
- [ ] Falling back because nothing was stored does not log. Only a value that was present and unrecognised does, so the log stays worth reading.
- [ ] **An unrecognised package or delivery type is displayed as itself.** Wherever a type is shown to an admin, an unresolved one renders as its own value — `Package type 31`, `Delivery type pallet_xl` — never as a known type it is not. A log entry is not sufficient on its own: the admin must be able to see it without going looking.
- [ ] **An unrecognised numeric type is still sent.** beta.31 serializes an unknown id unchanged, so an order carrying one stays exportable up to the API call and fails there with the API's own message about that type. The module does not pre-empt that judgement with a local allow-list.
- [ ] **An unrecognised non-numeric type fails before the call**, with a message naming the value, because no id can be derived for it. The failure is per shipment, not per batch.

## Priority

**Classification:** Must Have

**Justification:** The module moves from computing capability answers locally with no I/O to retrieving them over the network. That introduces a new class of failure affecting every export. Without this requirement the migration trades hardcoded-but-reliable for accurate-but-fragile, which is a bad trade for merchants.

## Technical Considerations

### Referenced Technical Requirements

- [TR-000007 — Capabilities retrieval and storage](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md) — the concrete rules, the fallback behaviour, and the unknown-value logging hook.
- [TR-000005 — SDK v11 API mapping and constant ownership](../technical-requirements/TR-000005-sdk-v11-api-mapping.md) — records this divergence from the PDK and its reasoning.

### Notes

The SDK supports this direction: `Support\EnumFallback` passes unknown enum values through unchanged and exposes a listener so they can be observed rather than lost. That listener is the natural implementation of criterion 4, and the same class documents the asymmetry behind criterion 7. Wiring and version detail in [TR-000007](../technical-requirements/TR-000007-capabilities-retrieval-and-storage.md).

The SDK's own request mapper for capabilities deliberately does not filter enum-like values, "to preserve forward compatibility when the API adds new allowable values" — the same principle, stated upstream.

## Dependencies

### Upstream (this FR depends on)

- [FR-000008 — Carrier capabilities and contract definitions](FR-000008-carrier-capabilities-and-contract-definitions.md) — supplies the capability data whose use this FR constrains. There is no data to degrade gracefully over until FR-000008 retrieves it.
- SDK `Support\EnumFallback` (beta.29 or later).

### Downstream (depends on this FR)

- [FR-000009 — Insurance as a range](FR-000009-insurance-as-a-range.md) — unresolvable bounds must not disable insurance.

## Cross-References

- **Also implements:** BR-000003 (primary parent).

## Implementation Notes

Implemented in Phase 4 of the [migration plan](../design/sdk-v11-migration.md), and constrains Phases 5 through 8.

This requirement is easy to erode by accident. A later change that adds "helpful" validation against capability data, or that aligns this module with the PDK's filtering, would silently reverse it. TR-000005 records the reasoning so that a future reader encountering the absent filter understands it is a decision rather than an omission.

Test it by injecting failure, not by hoping: a stubbed client that returns HTTP 500, one that times out, one that returns a response with an extra unknown option, and one that returns a response missing an expected key. Each should leave the module working.
