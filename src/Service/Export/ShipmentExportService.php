<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Client\Generated\CoreApi\ApiException;
use MyParcelNL\Sdk\Collection\ShipmentCollection;
use MyParcelNL\Sdk\Services\Labels\ShipmentLabelsService;
use MyParcelNL\Sdk\Services\Returns\ReturnShipmentService;
use MyParcelNL\Sdk\Services\Shipment\ShipmentCreateService;
use MyParcelNL\Sdk\Services\Shipment\ShipmentDeleteService;
use MyParcelNL\Sdk\Services\Shipment\ShipmentQueryService;
use Throwable;

/**
 * Groups built shipments by resolved API key *value* (never store id — stores share keys), sends
 * each group in chunks, and records what came back (TR-000006).
 *
 * Invariant: every chunk's shipment ids are stored before the next call is issued, and an order
 * already carrying an id is skipped — the API deduplicates nothing, so the stored id is all that
 * stands between a re-run and a second billable shipment.
 */
class ShipmentExportService
{
    public const DEFAULT_CHUNK_SIZE = 20;

    /** Imposed by the SDK's generated request model, which throws above it. */
    private const MAX_CHUNK_SIZE = 100;

    private const XML_PATH_CHUNK_SIZE = 'print/export_chunk_size';

    private const USER_AGENT_PROPOSITION = 'Magento';

    private ShipmentApiProvider $apiProvider;
    private Config              $config;
    private LabelPdfMerger      $labelPdfMerger;

    public function __construct(ShipmentApiProvider $apiProvider, Config $config, LabelPdfMerger $labelPdfMerger)
    {
        $this->apiProvider    = $apiProvider;
        $this->config         = $config;
        $this->labelPdfMerger = $labelPdfMerger;
    }

    /**
     * @param BuiltShipment[] $builtShipments
     */
    public function createConcepts(array $builtShipments): ExportReport
    {
        $report    = new ExportReport();
        $pending   = $this->withoutAlreadyShipped($builtShipments, $report);
        $chunkSize = $this->chunkSize();
        $groups    = $this->groupByApiKey($pending);

        Logger::notice(sprintf(
            'MyParcel export: %d shipments over %d API keys, chunk size %d',
            count($pending),
            count($groups),
            $chunkSize
        ));

        foreach ($groups as $apiKey => $group) {
            $this->createForKey((string) $apiKey, $group, $chunkSize, $report);
        }

        return $report;
    }

    /**
     * Status and barcode for shipments that already exist, one call per key.
     *
     * @param array<string,int[]> $shipmentIdsByApiKey
     *
     * @return array<int,object> MyParcel shipment id => ShipmentDefsShipment
     */
    public function fetchLatest(array $shipmentIdsByApiKey): array
    {
        $latest = [];

        foreach ($shipmentIdsByApiKey as $apiKey => $shipmentIds) {
            $shipmentIds = $this->normalizeIds($shipmentIds);

            if (! $shipmentIds) {
                continue;
            }

            try {
                $service = $this->tagged(new ShipmentQueryService((string) $apiKey, $this->apiProvider->clientFor((string) $apiKey)));

                foreach ($service->findMany($shipmentIds) as $shipment) {
                    $latest[(int) $shipment->getId()] = $shipment;
                }
            } catch (Throwable $e) {
                Logger::warning('MyParcel export: could not refresh shipments for one account', LogContext::of($e));
            }
        }

        return $latest;
    }

    /**
     * One label PDF for the whole batch, whatever accounts it spans.
     *
     * ShipmentLabelsService keeps a single PDF string per instance, so each key gets its own service
     * and the documents are merged here. Page order follows the order the ids are handed in.
     *
     * A failing account never costs the other accounts their labels: its error is returned alongside
     * whatever merged, so the caller can say why the PDF is empty or incomplete instead of guessing.
     *
     * @param array<string,int[]> $shipmentIdsByApiKey
     *
     * @return array{pdf: string, errors: string[]}
     */
    public function fetchLabelPdf(array $shipmentIdsByApiKey, $positions = 1): array
    {
        $pdfs   = [];
        $errors = [];

        foreach ($shipmentIdsByApiKey as $apiKey => $shipmentIds) {
            $shipmentIds = $this->normalizeIds($shipmentIds);

            if (! $shipmentIds) {
                continue;
            }

            try {
                // The third argument is the PSR client the service sends with; LabelHttpClient
                // exists so a non-PDF answer is visible, since the SDK discards the body it refused.
                $service = $this->tagged(new ShipmentLabelsService(
                    (string) $apiKey,
                    $this->apiProvider->clientFor((string) $apiKey),
                    new LabelHttpClient()
                ));
                $service->setPdfOfLabels($shipmentIds, $positions);

                $pdfs[] = $service->getLabelPdf();
            } catch (Throwable $e) {
                Logger::warning('MyParcel export: could not fetch labels for one account', LogContext::of($e));
                $errors[] = $e->getMessage();
            }
        }

        return ['pdf' => $this->labelPdfMerger->merge($pdfs), 'errors' => $errors];
    }

    /**
     * @param array<string,int[]> $shipmentIdsByApiKey
     */
    public function delete(array $shipmentIdsByApiKey): void
    {
        foreach ($shipmentIdsByApiKey as $apiKey => $shipmentIds) {
            if (! $shipmentIds) {
                continue;
            }

            try {
                $service = $this->tagged(new ShipmentDeleteService((string) $apiKey, $this->apiProvider->clientFor((string) $apiKey)));
                $service->deleteMany($shipmentIds);
            } catch (Throwable $e) {
                Logger::warning('MyParcel export: could not delete shipments for one account', LogContext::of($e));
            }
        }
    }

    /**
     * Return shipments against each parent shipment's own account (FR-000007).
     *
     * @param array<string,array<int,array>> $rowsByApiKey rows as ReturnShipmentService takes them
     * @param bool                           $sendMail     mail each label to the customer
     *
     * @return string[] error messages, one per failing account, for the caller to render
     */
    public function createReturns(array $rowsByApiKey, bool $sendMail): array
    {
        $errors = [];

        foreach ($rowsByApiKey as $apiKey => $rows) {
            if (! $rows) {
                continue;
            }

            try {
                $service = $this->tagged(new ReturnShipmentService((string) $apiKey, $this->apiProvider->clientFor((string) $apiKey)));
                $service->createRelated($rows, $sendMail);
            } catch (Throwable $e) {
                Logger::warning('MyParcel export: could not create return shipments for one account', LogContext::of($e));
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * One client and one service per key, reused across that key's chunks. Building them per chunk
     * would multiply the client construction the provider exists to do once.
     *
     * @param BuiltShipment[] $group
     */
    private function createForKey(string $apiKey, array $group, int $chunkSize, ExportReport $report): void
    {
        try {
            $service = $this->tagged(new ShipmentCreateService($apiKey, $this->apiProvider->clientFor($apiKey)));
        } catch (Throwable $e) {
            $this->failAll($group, $report, $e->getMessage());

            return;
        }

        $chunkNumber = 0;

        foreach (array_chunk($group, $chunkSize) as $chunk) {
            $chunkNumber++;
            $this->sendChunk($service, $chunk, $report, $chunkNumber);
        }
    }

    /**
     * Sends one chunk, and on a validation rejection sends the remainder once more without the
     * orders the API named.
     *
     * The API refuses a chunk whole, so one bad order used to cost all twenty. It does report every
     * faulty shipment in a single response, which is what makes **one** retry enough: after excluding
     * everything the first response blamed, nothing faulty is left. A second rejection is therefore
     * not expected, and is reported rather than retried again.
     *
     * @param BuiltShipment[] $chunk
     */
    private function sendChunk(ShipmentCreateService $service, array $chunk, ExportReport $report, int $chunkNumber): void
    {
        $rejection = $this->attempt($service, $chunk, $report, $chunkNumber);

        if (null === $rejection) {
            return;
        }

        $remainder = $this->without($chunk, $rejection['blamed']);

        if (! $remainder || ! $rejection['retryable']) {
            $this->reportCollateral($chunk, $rejection, $report);

            return;
        }

        Logger::notice(sprintf(
            'MyParcel export: chunk %d retried without %d refused order(s)',
            $chunkNumber,
            count($chunk) - count($remainder)
        ));

        $retry = $this->attempt($service, $remainder, $report, $chunkNumber);

        if (null !== $retry) {
            // Not expected: the first response should have named everything. Report and stop.
            $this->reportCollateral($remainder, $retry, $report);
        }
    }

    /**
     * @param BuiltShipment[] $chunk
     *
     * @return array{blamed: array<string,true>, summary: string, retryable: bool}|null
     *         null when the chunk shipped
     */
    private function attempt(ShipmentCreateService $service, array $chunk, ExportReport $report, int $chunkNumber): ?array
    {
        // Only the API call sits in this try: a failure while *recording* a successful call must
        // never read as an API rejection — the shipments exist upstream and are billable.
        try {
            $collection = new ShipmentCollection();

            foreach ($chunk as $built) {
                $collection->push($built->shipment());
            }

            $created = $service->create($collection);
        } catch (Throwable $e) {
            // Earlier chunks stay recorded — they exist upstream and are billable.
            Logger::warning(sprintf('MyParcel export: chunk %d failed', $chunkNumber), LogContext::of($e));

            return $this->attributeFailure($e, $chunk, $report);
        }

        $this->recordCreated($created, $chunk, $report);

        Logger::notice(sprintf('MyParcel export: chunk %d of %d shipments created', $chunkNumber, count($chunk)));

        return null;
    }

    /**
     * Whether re-sending is safe, which is a question about what the failed call left behind.
     *
     * A 422 means the API validated the request and refused it, so nothing was created. Anything else
     * — a timeout, a 5xx, a transport error — may have been processed, and the API deduplicates
     * nothing (TR-000006), so a retry would risk a second billable shipment. Widening this beyond 422
     * needs that same argument made for the status being added.
     */
    private function isSafeToRetry(Throwable $e): bool
    {
        return $e instanceof ApiException && 422 === $e->getCode();
    }

    /**
     * @param BuiltShipment[]      $chunk
     * @param array<string,true>   $blamed
     *
     * @return BuiltShipment[]
     */
    private function without(array $chunk, array $blamed): array
    {
        return array_values(array_filter(
            $chunk,
            static fn(BuiltShipment $built): bool => ! isset($blamed[$built->incrementId()])
        ));
    }

    /**
     * @param BuiltShipment[]                                                    $chunk
     * @param array{blamed: array<string,true>, summary: string, retryable: bool} $rejection
     */
    private function reportCollateral(array $chunk, array $rejection, ExportReport $report): void
    {
        foreach ($chunk as $built) {
            if (isset($rejection['blamed'][$built->incrementId()])) {
                continue;
            }

            $report->failCollateral($built->incrementId(), $rejection['summary']);
        }
    }

    /**
     * Puts each error from a rejected chunk against the order it belongs to.
     *
     * The API refuses a batch as a whole, so without this every order in the chunk gets the same
     * message and the admin cannot tell which one is at fault.
     *
     * **The body is RFC 9457 Problem Details, which is not what the spec documents.** The Core API
     * spec's `common_responses_user_error` declares `message` plus `errors[]` of
     * `{status, code, title, message}`; what actually arrives is `{type, title, detail, instance}`
     * per error, with the summary in `detail`. The generated model declares neither `detail` nor
     * `instance`, so deserializing loses both the useful text and the only pointer to a shipment —
     * hence json_decode on getResponseBody(). Both shapes are read, because the spec is what the
     * next SDK regeneration will follow.
     *
     * `instance` is a JSON Pointer — `/data/shipments/0/recipient/postal_code` — and the index in it
     * is the position in the request, which is this chunk's own order.
     *
     * @param BuiltShipment[] $chunk in request order, which is what an index in a pointer refers to
     *
     * @return array{blamed: array<string,true>, summary: string, retryable: bool}
     */
    private function attributeFailure(Throwable $e, array $chunk, ExportReport $report): array
    {
        $body = $e instanceof ApiException ? (string) $e->getResponseBody() : '';

        if ('' !== $body) {
            // Logged whole and once: the shape is not the documented one, so this line is how the
            // next divergence becomes visible rather than silently unattributed.
            Logger::warning('MyParcel export: rejection body ' . $body);
        }

        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $summary = trim((string) ($decoded['detail'] ?? $decoded['message'] ?? ''));
        $summary = '' !== $summary ? $summary : $e->getMessage();

        $errors = $decoded['errors'] ?? [];
        $errors = is_array($errors) ? $errors : [];
        $keyed  = array_keys($errors) !== range(0, count($errors) - 1);

        $blamed     = [];
        $unattached = [];

        foreach ($errors as $key => $error) {
            $text = $this->errorText($error);

            if ('' === $text) {
                continue;
            }

            // The pointer is the error's own `instance`; a keyed object's key is the fallback the
            // spec's shape would give us.
            $pointer = (string) (is_array($error) ? ($error['instance'] ?? '') : '');
            $pointer = '' !== $pointer ? $pointer : ($keyed ? (string) $key : '');
            $index   = $this->shipmentIndexIn($pointer);

            if (null !== $index && isset($chunk[$index])) {
                $incrementId          = $chunk[$index]->incrementId();
                $blamed[$incrementId] = true;
                $report->fail($incrementId, $this->withField($pointer, $text));
                continue;
            }

            $unattached[] = $text;
        }

        // An error nobody claimed is about the batch, not about one order, so it joins the summary
        // rather than being pinned on every order in turn.
        if ($unattached) {
            $summary = trim($summary . ' ' . implode('; ', $unattached));
        }

        return [
            'blamed'    => $blamed,
            'summary'   => $summary,
            // Retrying is only safe when nothing was created, and only worth it when we know who to
            // leave out.
            'retryable' => [] !== $blamed && $this->isSafeToRetry($e),
        ];
    }

    /** The index of the shipment a JSON Pointer points at, or null when it names no shipment. */
    private function shipmentIndexIn(string $pointer): ?int
    {
        if (preg_match('#shipments\D{0,2}(\d+)#i', $pointer, $matches)) {
            return (int) $matches[1];
        }

        return ctype_digit($pointer) ? (int) $pointer : null;
    }

    /**
     * The API's own wording, preferring the sentence that names the offending value.
     *
     * `detail` is RFC 9457's; `message` is what the spec declares. `title` alone is a category
     * ("Invalid postal code") and only stands in when there is nothing better.
     *
     * @param mixed $error
     */
    private function errorText($error): string
    {
        if (is_string($error)) {
            return trim($error);
        }

        if (! is_array($error)) {
            return '';
        }

        $title  = trim((string) ($error['title'] ?? ''));
        $detail = trim((string) ($error['detail'] ?? $error['message'] ?? ''));

        if ('' === $detail) {
            return $title;
        }

        return '' === $title || $title === $detail ? $detail : $title . ' — ' . $detail;
    }

    /**
     * Keeps the field the API objected to visible, trimmed of the envelope the admin does not need:
     * `/data/shipments/0/recipient/postal_code` reads as `recipient.postal_code`.
     */
    private function withField(string $pointer, string $text): string
    {
        // Slash for a JSON Pointer, dot for the dotted key the documented shape would use.
        if (! preg_match('#shipments[/.]\d+[/.](.+)$#', $pointer, $matches)) {
            return $text;
        }

        return sprintf('%s (%s)', $text, str_replace('/', '.', $matches[1]));
    }

    /**
     * Correlates by reference identifier, never by position: TR-000006 forbids relying on result
     * order, and the API is free to answer in any.
     *
     * @param array<int,string|null> $created shipment id => reference identifier
     * @param BuiltShipment[]        $chunk
     */
    private function recordCreated(array $created, array $chunk, ExportReport $report): void
    {
        $byReference = [];

        foreach ($chunk as $built) {
            $byReference[$built->referenceIdentifier()] = $built;
        }

        foreach ($created as $shipmentId => $referenceIdentifier) {
            $built = $byReference[(string) $referenceIdentifier] ?? null;

            if (null === $built) {
                Logger::warning(sprintf(
                    'MyParcel export: shipment %d came back with reference "%s", which no order in this chunk claims',
                    $shipmentId,
                    (string) $referenceIdentifier
                ));
                continue;
            }

            unset($byReference[$built->referenceIdentifier()]);

            try {
                $this->persist($built, (int) $shipmentId);
                $report->succeed($built->incrementId(), (int) $shipmentId);
            } catch (Throwable $e) {
                // The shipment exists and is billable; only our record of it failed. Said as such,
                // so the admin does not re-export a shipment that is already there.
                Logger::warning(
                    sprintf('MyParcel export: shipment %d created but its id could not be stored', $shipmentId),
                    LogContext::of($e)
                );
                $report->fail($built->incrementId(), sprintf(
                    'created as MyParcel shipment %d, but the id could not be stored: %s',
                    $shipmentId,
                    $e->getMessage()
                ));
            }
        }

        // Anything the response did not name did not ship, whatever the call's status was.
        foreach ($byReference as $built) {
            $report->fail($built->incrementId(), (string) __('The MyParcel API did not return a shipment for this order.'));
        }
    }

    /**
     * Written per chunk, before the next call. This row is what makes a re-run safe.
     *
     * A track without an entity id cannot save itself: the observer flow builds its tracks before
     * the shipment save that gives them a parent, and core's validator refuses a parentless track.
     * There the data set here is persisted by that same shipment save, moments later.
     */
    private function persist(BuiltShipment $built, int $shipmentId): void
    {
        $track = $built->track();
        $track->setData('myparcel_consignment_id', $shipmentId);
        $track->setData('myparcel_status', 1);

        if ($track->getId()) {
            $track->save();
        }
    }

    /**
     * @param BuiltShipment[] $builtShipments
     *
     * @return BuiltShipment[]
     */
    private function withoutAlreadyShipped(array $builtShipments, ExportReport $report): array
    {
        $pending = [];

        foreach ($builtShipments as $built) {
            $existing = (int) $built->track()->getData('myparcel_consignment_id');

            if (0 < $existing) {
                $report->succeed($built->incrementId(), $existing);
                continue;
            }

            $pending[] = $built;
        }

        return $pending;
    }

    /**
     * @param BuiltShipment[] $builtShipments
     *
     * @return array<string,BuiltShipment[]>
     */
    private function groupByApiKey(array $builtShipments): array
    {
        $groups = [];

        foreach ($builtShipments as $built) {
            $groups[$built->apiKey()][] = $built;
        }

        return $groups;
    }

    /** @param BuiltShipment[] $builtShipments */
    private function failAll(array $builtShipments, ExportReport $report, string $reason): void
    {
        foreach ($builtShipments as $built) {
            $report->fail($built->incrementId(), $reason);
        }
    }

    /**
     * Tags a per-key SDK service with the module's user agent. Every service goes through here, so
     * the tag cannot be forgotten on the next one.
     *
     * @template T of object
     *
     * @param T $service
     *
     * @return T
     */
    private function tagged(object $service): object
    {
        $service->setUserAgentForProposition(self::USER_AGENT_PROPOSITION, $this->apiProvider->userAgentVersion());

        return $service;
    }

    /** @return int[] deduplicated, reindexed, without empties */
    private function normalizeIds(array $shipmentIds): array
    {
        return array_values(array_unique(array_filter($shipmentIds)));
    }

    /** Anything outside 1..100 — including a configured 0, which would loop forever — falls back. */
    private function chunkSize(): int
    {
        $configured = $this->config->getGeneralConfig(self::XML_PATH_CHUNK_SIZE);

        if (! is_numeric($configured)) {
            return self::DEFAULT_CHUNK_SIZE;
        }

        $size = (int) $configured;

        return 1 <= $size && $size <= self::MAX_CHUNK_SIZE ? $size : self::DEFAULT_CHUNK_SIZE;
    }
}
