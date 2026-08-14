# TR-000006: Per-API-Key Export Batching

## Related Functional Requirements

- [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)
- [FR-000006 — Shipment export via SDK v11 shipment services](../functional-requirements/FR-000006-shipment-export-via-sdk-v11.md)

## Related ADRs

- None.

## Category

Reliability / Compatibility

## Requirement

Export operations must be grouped by MyParcel API key, issued through one API client per key, chunked at a configurable size defaulting to **20** shipments per call, and persisted per chunk so that a mid-batch failure never leaves a created shipment without a Magento reference.

## Rationale

SDK v11 removed per-key grouping along with `MyParcelCollection`: the API key is now a constructor argument on each `final` service, a collection has no key concept, and no grouping remains. Since the API key is store-scoped and no admin grid mass action filters by store, mixed-account batches occur routinely, so the module must own the grouping (FR-000007).

Chunking is a separate concern with an operational cause. The SDK rejects more than 100 shipments per call, but batches approaching that limit time out often enough in production that 100 is the wrong default. A smaller default trades round-trips for reliability, and making it configurable lets a merchant on slow infrastructure lower it further without a module release.

Chunking then creates a failure mode that did not previously exist. A 100-order batch at 20 per call is five calls, and call four can fail after three have created real shipments in MyParcel. Without per-chunk persistence those shipments exist upstream, are billable, and have no local record — worse than the whole batch failing.

## Specifications

### Grouping

- The API key is resolved per order from `myparcelnl_magento_general/api/key` at the order's store scope. Never from an ambient or request-derived store.
- Orders are grouped by resolved key. Each group is sent only under its own key.
- Grouping is by the resolved key **value**, not by store id. Magento resolves the path through a default → website → store fallback (`Store\Model\Config\Processor\Fallback`), so several stores commonly resolve to one inherited key. Their orders form a single group and a single call. Keying the grouping on store id instead splits one account's batch into as many calls as it has stores, which multiplies round-trips and defeats the chunk size.
- The group count is therefore the number of distinct resolved keys — neither the number of stores nor the number of orders. A website-scope override puts that website's orders in their own group, while the remaining stores stay together on the inherited default.
- An order whose store resolves an empty key is excluded from the batch and reported by increment id. It must not be sent under another key.
- Grouping applies to: shipment creation, status/query refresh, label retrieval, track & trace retrieval, concept deletion, and return-shipment creation. The fulfilment (PPS) path already groups internally and needs no additional layer.

### Client construction

| Criterion | Requirement |
|---|---|
| Clients per API key | Exactly one `ShipmentApi`, built via `Services\CoreApi\ShipmentApiFactory::make($apiKey)` |
| Client reuse | That one client is injected into every service for that key (`ShipmentCreateService`, `ShipmentQueryService`, `ShipmentLabelsService`, `ShipmentTrackTraceService`, `ReturnShipmentService`, `ShipmentDeleteService`) via their second constructor argument, rather than letting each build its own |
| Empty key handling | An empty or missing key raises the module's existing `LocalizedException` **before** reaching the SDK factory |
| User agent | Set per service via `setUserAgentForProposition('Magento', <version>)`, replacing the removed `MyParcelCollection::setUserAgents()` |

`MultiColloShipmentService` takes no API key and is purely in-memory — it must not be built per key.

**Empty-key hazard.** `ShipmentApiFactory::resolveApiKey()` falls back to `getenv('API_KEY')`, then `API_KEY_NL` / `API_KEY_BE`, when handed an empty string. Passing an unvalidated key through would send a merchant's parcels to whichever account those variables point at, with no error. The guard above is therefore mandatory, not defensive.

### Chunking

| Criterion | Requirement |
|---|---|
| Default chunk size | 20 shipments per `create()` call |
| Configurable | Admin setting in `etc/dynamic_settings.json` |
| Valid range | 1 to 100 inclusive |
| Invalid or missing value | Falls back to 20. A configured `0` must not produce a zero-length request or an unbounded loop |
| Hard ceiling | 100, imposed by the SDK's generated request model |

### Partial-failure semantics

1. Each chunk's returned `[shipmentId => referenceIdentifier]` mapping is written to the corresponding Magento shipment tracks **before** the next chunk is issued.
2. A chunk failure does not roll back or discard earlier chunks.
3. The admin receives a per-order report distinguishing orders that shipped from orders that did not, identified by increment id.
4. A failure mid-batch leaves the module in a resumable state: re-running the action over the failed orders must not duplicate the shipments already created.

### Correlation back to Magento

`create()` returns `[shipmentId => referenceIdentifier]`. The reference identifier is the Magento shipment entity id, so correlation is by reference identifier rather than by the removed `getConsignmentsByReferenceId()` and `getConsignmentByApiId()` lookups. Correlation must not rely on result ordering.

### Label PDF merging

- Labels are retrieved per API key via `ShipmentLabelsService::setPdfOfLabels()`, then merged into one document using `setasign/fpdi`.
- `setasign/fpdi` becomes an **explicit** module dependency (`^2.6`). beta.31 still declares it but no longer uses it, so relying on it transitively is fragile.
- Merged output preserves the admin's selection order, not account grouping.
- Label positions and paper size behaviour are unchanged: A4 honours positions, A6 does not.

## Verification Method

Unit tests with a mocked `ShipmentApi` for the mechanics, plus manual multi-account verification against acceptance credentials for the end-to-end behaviour.

### Test Scenarios

1. **N keys produce N create calls.** A batch spanning three distinct keys issues exactly three create calls, each carrying only its own key's shipments.
2. **Stores sharing an inherited key produce one call.** A batch spanning three stores that all resolve to the same default-scope key issues exactly one create call carrying all of them. Adding a website-scope override for one of those stores splits the same batch into two calls, not three.
3. **One client per key.** Building the services for a key constructs one `ShipmentApi`, not one per service.
4. **Chunk boundaries.** 50 shipments at the default produce three calls of 20, 20 and 10. Chunk size 1 produces 50 calls. Chunk size 100 produces one. Chunk sizes `0`, `-1`, `101` and a non-numeric value all fall back to 20.
5. **Per-chunk persistence.** With the mock failing on chunk 3, the tracks for chunks 1 and 2 carry their shipment ids and barcodes, and the report names the orders in chunk 3 as failed.
6. **Empty key fails loudly.** An order whose store has no key raises `LocalizedException` and never reaches `ShipmentApiFactory`, verified with the `API_KEY` environment variable deliberately set to a decoy value that must not be used.
7. **Correlation without ordering.** A response returning mappings in a different order from the request still correlates each shipment to the correct Magento track.
8. **Merged PDF.** Two keys yielding two PDFs produce one document whose page count is the sum, ordered by the admin's selection.
9. **Manual, two stores, two accounts.** A mixed mass action places each shipment in its correct MyParcel backoffice and returns one merged PDF.
10. **Manual, chunk timeout.** ~50 orders at the default size complete without timeout.

### Monitoring

- Log the number of distinct API keys, chunks issued, and per-chunk outcome for each batch export. This is the trail support needs to explain a partial failure.
- Never log the API key itself, in plaintext or otherwise.

## Assumptions

- The MyParcel API is idempotent with respect to reference identifiers to the extent that a re-run over failed orders does not duplicate already-created shipments. **To be confirmed against acceptance** before relying on scenario 5's resumability claim.
- `ShipmentApiFactory::make()` is cheap enough to call once per key per request; it builds a fresh Guzzle client with no shared mutable state.
- The fulfilment path's internal per-order key grouping continues to work at beta.31, as observed in `Collection\Fulfilment\OrderCollection::save()`.

## Constraints

- The SDK's 100-shipment ceiling cannot be raised from the module.
- `ShipmentLabelsService` holds a single PDF string per instance, so cross-account merging cannot be delegated to it and must happen in module code.
- `downloadPdfOfLabels()` sends headers and exits, so it must be the last call in a request.
