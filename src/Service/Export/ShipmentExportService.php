<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Collection\ShipmentCollection;
use MyParcelNL\Sdk\Services\Labels\ShipmentLabelsService;
use MyParcelNL\Sdk\Services\Returns\ReturnShipmentService;
use MyParcelNL\Sdk\Services\Shipment\ShipmentCreateService;
use MyParcelNL\Sdk\Services\Shipment\ShipmentDeleteService;
use Throwable;
use MyParcelNL\Magento\Service\IdList;

/**
 * Groups built shipments by resolved API key *value* (never store id — stores share keys), sends
 * each group in chunks, and records what came back.
 *
 * Invariant: every chunk's shipment ids are stored before the next call is issued, and an order
 * already carrying an id is skipped — the API deduplicates nothing, so the stored id is all that
 * stands between a re-run and a second billable shipment.
 */
class ShipmentExportService
{
    /**
     * The shipments-per-request default, which Config owns because the PPS export chunks by the
     * same setting. The SDK's generated request model throws above 100, and the admin field will
     * not accept more, so the bound is enforced there rather than here.
     */
    public const DEFAULT_CHUNK_SIZE = Config::DEFAULT_EXPORT_CHUNK_SIZE;

    /** Label ids per request. See ShipmentQuery::CHUNK_SIZE — same URL limit, same multiple of four. */
    private const LABEL_CHUNK_SIZE = 100;

    private ShipmentApiProvider $apiProvider;
    private Config              $config;
    private LabelPdfMerger      $labelPdfMerger;
    private UserAgent           $userAgent;

    public function __construct(
        ShipmentApiProvider $apiProvider,
        Config              $config,
        LabelPdfMerger      $labelPdfMerger,
        UserAgent           $userAgent
    )
    {
        $this->apiProvider    = $apiProvider;
        $this->config         = $config;
        $this->labelPdfMerger = $labelPdfMerger;
        $this->userAgent      = $userAgent;
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

        $this->perKey(
            array_map([$this, 'normalizeIds'], $shipmentIdsByApiKey),
            'refresh shipments',
            function (string $apiKey, array $shipmentIds) use (&$latest): void {
                $service = $this->tagged($apiKey, static function (string $key, $client) {
                    return new ShipmentQuery($client);
                });

                $latest += $service->findMany($shipmentIds);
            }
        );

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
        $pdfs = [];

        $errors = $this->perKey(
            array_map([$this, 'normalizeIds'], $shipmentIdsByApiKey),
            'fetch labels',
            function (string $apiKey, array $shipmentIds) use (&$pdfs, $positions): void {
                // Chunked for the same reason the query is: every id goes into one path segment.
                // LABEL_CHUNK_SIZE is a multiple of four, so an A4 sheet's four positions still
                // fall on the same sheet as they would unchunked.
                foreach (array_chunk($shipmentIds, self::LABEL_CHUNK_SIZE) as $chunk) {
                    // The third argument is the PSR client the service sends with; LabelHttpClient
                    // exists so a non-PDF answer is visible, since the SDK discards the body it refused.
                    $service = $this->tagged($apiKey, static function (string $key, $client) {
                        return new ShipmentLabelsService($key, $client, new LabelHttpClient());
                    });
                    $service->setPdfOfLabels($chunk, $positions);

                    $pdfs[] = $service->getLabelPdf();
                }
            }
        );

        try {
            $pdf = $this->labelPdfMerger->merge($pdfs);
        } catch (Throwable $e) {
            // FPDI throws on a document it cannot parse. The controller already knows how to say
            // "no labels"; letting this escape turns it into a 500 instead.
            Logger::warning('MyParcel export: the label PDFs could not be merged', LogContext::of($e));

            return ['pdf' => '', 'errors' => array_merge($errors, [$e->getMessage()])];
        }

        return ['pdf' => $pdf, 'errors' => $errors];
    }

    /**
     * @param array<string,int[]> $shipmentIdsByApiKey
     */
    public function delete(array $shipmentIdsByApiKey): void
    {
        $this->perKey(
            $shipmentIdsByApiKey,
            'delete shipments',
            function (string $apiKey, array $shipmentIds): void {
                $this->tagged($apiKey, static function (string $key, $client) {
                    return new ShipmentDeleteService($key, $client);
                })->deleteMany($shipmentIds);
            }
        );
    }

    /**
     * Return shipments against each parent shipment's own account.
     *
     * @param array<string,array<int,array>> $rowsByApiKey rows as ReturnShipmentService takes them
     * @param bool                           $sendMail     mail each label to the customer
     *
     * @return string[] error messages, one per failing account, for the caller to render
     */
    public function createReturns(array $rowsByApiKey, bool $sendMail): array
    {
        return $this->perKey(
            $rowsByApiKey,
            'create return shipments',
            function (string $apiKey, array $rows) use ($sendMail): void {
                $this->tagged($apiKey, static function (string $key, $client) {
                    return new ReturnShipmentService($key, $client);
                })->createRelated($rows, $sendMail);
            }
        );
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
            $service = $this->tagged($apiKey, static function (string $key, $client) {
                return new ShipmentCreateService($key, $client);
            });
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

        $remainder = $this->without($chunk, $rejection);

        if (! $remainder || ! $rejection->isRetryable()) {
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
     * @return Rejection|null null when the chunk shipped
     */
    private function attempt(ShipmentCreateService $service, array $chunk, ExportReport $report, int $chunkNumber): ?Rejection
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
     * @param BuiltShipment[] $chunk
     *
     * @return BuiltShipment[]
     */
    private function without(array $chunk, Rejection $rejection): array
    {
        return array_values(array_filter(
            $chunk,
            static fn(BuiltShipment $built): bool => ! $rejection->blames($built->incrementId())
        ));
    }

    /**
     * @param BuiltShipment[] $chunk
     */
    private function reportCollateral(array $chunk, Rejection $rejection, ExportReport $report): void
    {
        foreach ($chunk as $built) {
            if ($rejection->blames($built->incrementId())) {
                continue;
            }

            $report->failCollateral($built->incrementId(), $rejection->summary(), $rejection->isRefusal());
        }
    }

    /**
     * Turns one batch rejection into a reason per order, and records them.
     *
     * The parse itself is Rejection's; what stays here is writing the result to the report.
     *
     * @param BuiltShipment[] $chunk in request order, which is what an index in a pointer refers to
     */
    private function attributeFailure(Throwable $e, array $chunk, ExportReport $report): Rejection
    {
        $body = Rejection::bodyOf($e);

        if ('' !== $body) {
            // Logged whole and once: the shape is not the documented one, so this line is how the
            // next divergence becomes visible rather than silently unattributed.
            Logger::warning('MyParcel export: rejection body ' . $body);
        }

        $rejection = Rejection::fromApiException($e, $chunk);

        // (string) because PHP turns a numeric increment id into an int array key.
        foreach ($rejection->reasons() as $incrementId => $reasons) {
            foreach ($reasons as $reason) {
                $report->fail((string) $incrementId, $reason);
            }
        }

        return $rejection;
    }




    /**
     * Correlates by reference identifier, never by position: the API is free to answer in any
     * order, so result order must never be relied on.
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
        $this->stamp($built->track(), $shipmentId);

        // A multicollo's remaining rows take the same id: non-zero is what keeps them out of the
        // next export, and updateMagentoTrack() replaces it with each collo's own id once the
        // query response names them.
        foreach ($built->spareTracks() as $spare) {
            $this->stamp($spare, $shipmentId);
        }
    }

    private function stamp(Track $track, int $shipmentId): void
    {
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

    /** @return int[] deduplicated, reindexed, without empties */
    /**
     * The per-account loop every batch call shares: an empty group is skipped, and one account's
     * failure costs only its own work.
     *
     * @param array<string,array>            $byApiKey
     * @param string                         $failure what could not be done, for the log line
     * @param callable(string, array): void  $call
     *
     * @return string[] one message per failing account
     */
    private function perKey(array $byApiKey, string $failure, callable $call): array
    {
        $errors = [];

        foreach ($byApiKey as $apiKey => $items) {
            if (! $items) {
                continue;
            }

            try {
                $call((string) $apiKey, $items);
            } catch (Throwable $e) {
                Logger::warning("MyParcel export: could not $failure for one account", LogContext::of($e));
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * An SDK service for one account, carrying the module's user agents.
     *
     * @param callable(string, mixed): object $make receives the key and that key's client
     *
     * @return object the service $make built
     */
    private function tagged(string $apiKey, callable $make)
    {
        return $make($apiKey, $this->apiProvider->clientFor($apiKey))
            ->setUserAgents($this->userAgent->map());
    }

    private function normalizeIds(array $shipmentIds): array
    {
        return IdList::ints($shipmentIds);
    }

    /** Anything outside 1..100 — including a configured 0, which would loop forever — falls back. */
    private function chunkSize(): int
    {
        return $this->config->getExportChunkSize();
    }
}
