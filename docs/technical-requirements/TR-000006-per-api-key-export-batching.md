# TR-000006: Per-API-Key Export Batching

## Related Functional Requirements

- [FR-000007 — Multi-account batch export](../functional-requirements/FR-000007-multi-account-batch-export.md)
- [FR-000006 — Shipment export via SDK v11 shipment services](../functional-requirements/FR-000006-shipment-export-via-sdk-v11.md)

## Related ADRs

- None.

## Category

Reliability / Compatibility

## Requirement

Export operations are grouped by MyParcel API key, issued through one API client per key, chunked at a configurable size defaulting to **20** shipments per call, and persisted per chunk so that a mid-batch failure never leaves a created shipment without a Magento reference.

## Rationale

SDK v11 removed per-key grouping along with `MyParcelCollection`. The API key is store-scoped and no admin grid mass action filters by store, so mixed-account batches occur routinely and the module must own the grouping (FR-000007).

Chunking has an operational cause: the SDK rejects more than 100 shipments per call, and batches approaching that limit time out often enough that 100 is the wrong default. Chunking then creates a failure mode of its own: call four of five can fail after three have created real, billable shipments. Without per-chunk persistence those shipments exist upstream with no local record, which is worse than the whole batch failing.

## Specifications

### Grouping

- The API key is resolved per order from `myparcelnl_magento_general/api/key` at the order's store scope. Never from an ambient or request-derived store.
- Orders are grouped by the resolved key **value**, not by store id. Several stores commonly inherit one key through Magento's default → website → store fallback; their orders form one group and one call. Keying on store id would split one account's batch into as many calls as it has stores.
- An order whose store resolves an empty key is excluded and reported by increment id. It is never sent under another key.
- Grouping applies to shipment creation, status refresh, label retrieval, track & trace retrieval, concept deletion, return-shipment creation, fulfilment (PPS) order creation, and the PPS status query the `UpdateStatus` cron issues.
- The two PPS entries are the module's even though `Collection\Fulfilment\OrderCollection::save()` groups internally: grouping alone does not isolate, since one call fails the whole method. Each account is saved in its own try/catch and its orders are marked exported before the next call is issued.

### Client construction

| Criterion | Requirement |
|---|---|
| Clients per API key | Exactly one `ShipmentApi`, built via `Services\CoreApi\ShipmentApiFactory::make($apiKey)` in `Service\Export\ShipmentApiProvider` |
| Client reuse | That one client is injected into every service for that key (`ShipmentCreateService`, `ShipmentQueryService`, `ShipmentLabelsService`, `ShipmentTrackTraceService`, `ReturnShipmentService`, `ShipmentDeleteService`) via their second constructor argument |
| Empty key handling | An empty or missing key raises the module's `LocalizedException` **before** reaching the SDK factory |
| User agent | `Service\UserAgent::map()` through the SDK's own `setUserAgents()` on every service, and `header()` for the transport header the factory takes |

`MultiColloShipmentService` takes no API key and is purely in-memory; it is not built per key.

**Empty-key hazard, and why injection is the defence.** An explicitly empty key does not raise in the SDK: three factories resolve it from the environment, so a store with no key would ship against whatever account the environment names (defect 2 in [TR-000007](TR-000007-capabilities-retrieval-and-storage.md)). Three rules make that path unreachable:

1. **One choke point.** Exactly one place calls `ShipmentApiFactory::make()`. It resolves the key from the order's store, raises when empty, and only then calls the factory. The capabilities layer never calls the factory; it sends its own request (TR-000007).
2. **Inject, never let a service construct its own.** Every service accepts `?ShipmentApi $api` and falls back to the factory when null. Passing the module's client means the SDK never calls the factory on the module's behalf.
3. **No environment surgery.** Do not `putenv()` the variables away: php-fpm reuses process state across requests. Do not reimplement the factory either; its timeout, handler stack and user-agent setup would drift from the SDK's.

### Chunking

| Criterion | Requirement |
|---|---|
| Default chunk size | 20 shipments per `create()` call |
| Configurable | Admin setting in `etc/dynamic_settings.json` |
| Valid range | 1 to 100 inclusive |
| Invalid or missing value | Falls back to 20. A configured `0` must not produce a zero-length request or an unbounded loop |
| Hard ceiling | 100, imposed by the SDK's request model |

### Partial-failure semantics

1. Each chunk's returned `[shipmentId => referenceIdentifier]` mapping is written to the Magento shipment tracks **before** the next chunk is issued.
2. A chunk failure does not roll back or discard earlier chunks.
3. The admin receives a per-order report. A rejection names the order the API blamed with the API's own sentence and field; the other orders in that chunk are told they did not ship without being blamed.
4. **A rejected chunk is re-sent once without the orders the API named.** The API refuses a batch atomically with a 422 and reports every faulty shipment in one response, so one retry ships the rest. Only a 422 is safe to retry: it created nothing. A timeout or 5xx says nothing about whether the request was processed, and the API deduplicates nothing, so re-sending could bill twice.
5. **The module, not the API, makes a re-run safe.** The reference identifier is a string the module chooses; the API attaches no meaning to it. Re-sending an order that already shipped creates a second, billable shipment. Per-chunk persistence is therefore the record a re-run reads to know what to skip: an order carrying a MyParcel shipment id is excluded from a re-export unless the admin explicitly asks for another label.

### Correlation back to Magento

`create()` returns `[shipmentId => referenceIdentifier]`. The reference identifier is `<shipment entity id>-<collo number>`, always suffixed: a `label_amount` above one makes several Magento tracks for one shipment, and a shared reference would pair only one of them. Correlation is by reference identifier, never by result ordering.

### Label PDF merging

- Labels are retrieved per API key through `Service\Export\LabelHttpClient`, which also works around the SDK's percent-encoding of the `;` separator in the path and the positions query, then merged into one document with `setasign/fpdi` in `Service\Export\LabelPdfMerger`.
- `setasign/fpdi` is an **explicit** module dependency (`^2.6`).
- Merged output is grouped per API key: the pages of one account follow each other, in the order that account's ids were sent. Interleaving accounts is not possible on A4, where one sheet carries up to four labels of a single account, and cannot be proved on A6 while a multicollo shipment owns more than one page under one id.
- Label positions and paper size behaviour are unchanged: A4 honours positions, A6 does not.

## Verification Method

Unit tests with a mocked `ShipmentApi` for the mechanics, plus manual multi-account verification against acceptance credentials.

### Test Scenarios

1. **N keys produce N create calls.** Three distinct keys issue exactly three create calls, each carrying only its own key's shipments.
2. **Stores sharing an inherited key produce one call.** Three stores resolving to the same default-scope key issue one call. A website-scope override for one of them splits the batch into two calls, not three.
3. **One client per key.** Building the services for a key constructs one `ShipmentApi`, not one per service.
4. **Chunk boundaries.** 50 shipments at the default produce calls of 20, 20 and 10. Size 1 produces 50 calls; size 100 one. Sizes `0`, `-1`, `101` and a non-numeric value fall back to 20.
5. **Per-chunk persistence.** 50 shipments with the mock failing on chunk 3: the tracks for chunks 1 and 2 carry their shipment ids, and the report names the 10 orders in chunk 3.
6. **A re-run does not duplicate.** Re-running over scenario 5's orders sends only the 10 that failed. With every order already shipped, **zero** create calls.
7. **Empty key fails loudly.** An order whose store has no key raises `LocalizedException` and never reaches `ShipmentApiFactory`, with the `API_KEY` environment variable set to a decoy that must not be used.
8. **Correlation without ordering.** A response returning mappings in a different order still correlates each shipment to the correct track.
9. **Merged PDF.** Two keys yielding two PDFs produce one document whose page count is the sum, each account's pages together and in the order its ids were sent.
10. **Manual, two stores, two accounts.** Each shipment lands in its correct backoffice and one merged PDF downloads.
11. **Manual, chunk timeout.** About 50 orders at the default size complete without timeout.

### Monitoring

- Log the number of distinct API keys, chunks issued and per-chunk outcome for each batch export.
- Never log the API key itself, in plaintext or otherwise.

## Assumptions

- The API is **not** idempotent with respect to reference identifiers, confirmed by the MyParcel team. Hence points 4 and 5 above.
- `ShipmentApiFactory::make()` is cheap: it base64-encodes the key and builds a `Configuration`, a `HandlerStack` and a Guzzle client, with no I/O and no shared state. One per key per request is fine; one per service would multiply it sixfold.
- `Collection\Fulfilment\OrderCollection::save()` groups on `$order->getApiKey()`. **Hazard:** a collection-level key overwrites every order's key and sends the whole batch to one account, so `MagentoOrderCollection::setFulfilment()` must never set one.

## Constraints

- The SDK's 100-shipment ceiling cannot be raised from the module.
- `ShipmentLabelsService` holds a single PDF string per instance, so cross-account merging happens in module code.
- The export answers JSON and the labels are fetched by a second request (`Controller\Adminhtml\Order\PrintMyParcelLabels`): a response is a PDF or a page, never both.
