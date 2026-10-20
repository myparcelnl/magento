# Capability option dependencies

Findings for the PR that makes the module honour `requires` and `excludes`. Nothing here is
implemented: the data arrives, is parsed, and is discarded.

## The data is already there

Each option in a capabilities result carries `requires` and `excludes`, in v2 names. The module
parses the whole option body into `Model\Shipment\Capabilities\OptionSet` and reaches it through
`CapabilitySet::optionValue()`, which only ever yields the insurance bounds. The dependency keys sit
in the same array, unread.

`ShipmentOption::fromV2Name()` maps the names back. It answers null for a name the module does not
know, and the caller logs rather than substituting one, as everywhere else.

## The real graph

From `Tests/Fixtures/capabilities-acceptance-v2.json`. The pairings differ per carrier, so they are
read, never assumed.

| Carrier | Option | requires | excludes |
|---|---|---|---|
| PostNL | age check | only recipient, signature | receipt code, printerless return |
| PostNL | insurance | signature, only recipient | printerless return |
| PostNL | receipt code | insurance | age check, only recipient, signature, return, printerless return |
| PostNL | signature, only recipient, return | — | receipt code, printerless return |
| UPS | age check | signature | only recipient |
| DHL | insurance | signature | hide sender, printerless return |
| DHL | age check | — | only recipient, printerless return |

Two facts fall out of this table:

- **Almost every exclusion is mutual.** On PostNL, receipt code excludes age check, signature, only
  recipient and return, and each of those excludes receipt code. On UPS and DHL, age check and only
  recipient exclude each other. A rule that only applies one-directional exclusions does nothing.
- **A requires chain contradicts itself.** PostNL's receipt code requires insurance, insurance
  requires signature and only recipient, and receipt code excludes signature and only recipient.

## Keep `requires` single-level

`requires` names an option's valid companions, not a transitive closure. Follow PostNL's chain one
step further and it enables the two options receipt code exists to exclude.

The PDK recurses (`App\Order\Calculator\General\CapabilitiesOptionCalculator::applyRequiresChain()`,
with a `visited` set for circular requires) and then undoes the damage, because its `excludes` pass
runs after its `requires` pass inside the same loop body. That correctness rests on statement order
in one method. One level needs no such rescue.

## `excludes` needs a precedence, and the PDK's is accidental

The PDK forces every excluded option to `DISABLED`, iterating the definitions and reading each
option's current value as it mutates. The winner of a mutual exclusion is therefore whichever
definition comes first in `config/pdk-business-logic.php`, and that list is alphabetical.
`AgeCheckDefinition` precedes `ReceiptCodeDefinition`, so age check wins — by luck. Renaming a
definition class flips it, and the PDK's test suite pins requires, cascading requires and
one-directional excludes, but not the mutual case.

Do not copy that. Rank **where the value came from**, which needs no list of option names:

1. **Product attribute.** A property of the goods. 18+ is a legal fact, not a preference.
2. **Posted for this shipment** (`$this->options`). The operator's decision about this order.
3. **The checkout's stored delivery options.** The customer chose it, and may have paid for it.
4. **Configuration default.** A standing preference, not a decision about this order.

The higher tier wins. On equal tier the module does not arbitrate: both stay as chosen and the API
refuses, naming the order. Dropping one would silently lose an age check an 18+ order needs, which
`sdk-v11.md` already rules out.

Tier 1 has one member today. It earns its place as a rule about provenance rather than a named
exception, and a future product-driven flag joins it with no list to maintain.

## Worth a test

- A mutual exclusion, which the PDK's suite does not cover.
- Receipt code does not cascade into the options it excludes.
- An unknown dependency name is dropped and logged, never substituted.
- A permissive set forces nothing, so an unreachable contract cannot change a shipment.
