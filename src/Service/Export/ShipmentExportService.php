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
use Psr\Http\Client\ClientInterface as PsrClientInterface;
use Throwable;
use MyParcelNL\Magento\Service\IdList;

/**
 * Groups built shipments by resolved API key *value* (never store id: stores share keys), sends
 * each group in chunks, and records what came back.
 *
 * Invariant: each chunk's shipment ids are stored before the next call, and an order that already
 * has an id is skipped. The API deduplicates nothing, so the stored id prevents a second billable shipment.
 */
class ShipmentExportService
{
    /**
     * The shipments-per-request default, which Config owns because the Order v1 export chunks by the
     * same setting. The SDK's generated request model throws above 100, and the admin field will
     * not accept more, so the bound is enforced there rather than here.
     */
    public const DEFAULT_CHUNK_SIZE = Config::DEFAULT_EXPORT_CHUNK_SIZE;

    /** Label ids per request. Same URL limit and multiple of four as ShipmentQuery::CHUNK_SIZE. */
    private const LABEL_CHUNK_SIZE = 100;

    private ShipmentApiProvider $apiProvider;
    private Config              $config;
    private LabelPdfMerger      $labelPdfMerger;
    private UserAgent           $userAgent;

    /** Null in production, so the SDK builds its own client; a test sets one to answer offline. */
    private ?PsrClientInterface $returnHttpClient = null;

    /** Null in production, so a LabelHttpClient is built; a test sets one to answer offline. */
    private ?PsrClientInterface $labelHttpClient = null;

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
     * Fetch status and barcode for shipments that already exist, one call per key.
     * Here an unreachable account looks like an empty answer: to act on the answer, use fetchLatestWithErrors().
     *
     * @param array<string,int[]> $shipmentIdsByApiKey
     *
     * @return array<int,object> MyParcel shipment id => ShipmentDefsShipment
     */
    public function fetchLatest(array $shipmentIdsByApiKey): array
    {
        return $this->fetchLatestWithErrors($shipmentIdsByApiKey)['shipments'];
    }

    /**
     * Fetch like fetchLatest(), and also return the per-account failures.
     * `failedApiKeys` tells a failed lookup apart from an account that answered without the shipment.
     *
     * @param array<string,int[]> $shipmentIdsByApiKey
     *
     * @return array{shipments: array<int,object>, errors: string[], failedApiKeys: string[]}
     */
    public function fetchLatestWithErrors(array $shipmentIdsByApiKey): array
    {
        $latest   = [];
        $idsByKey = array_map([$this, 'normalizeIds'], $shipmentIdsByApiKey);
        $answered = [];

        $errors = $this->perKey(
            $idsByKey,
            'refresh shipments',
            function (string $apiKey, array $shipmentIds) use (&$latest, &$answered): void {
                $service = $this->tagged($apiKey, static function (string $key, $client) {
                    return new ShipmentQuery($client);
                });

                $latest += $service->findMany($shipmentIds);
                $answered[] = $apiKey;
            }
        );

        $asked = array_map('strval', array_keys(array_filter($idsByKey)));

        return [
            'shipments'     => $latest,
            'errors'        => $errors,
            'failedApiKeys' => array_values(array_diff($asked, $answered)),
        ];
    }

    /**
     * Fetch one merged label PDF for the whole batch, across accounts, in the order of the ids.
     *
     * A failing account or chunk does not cost the others their labels. Each failure's error comes
     * back with the PDF, so the caller can tell why it is empty or incomplete.
     *
     * @param array<string,int[]> $shipmentIdsByApiKey
     *
     * @return array{pdf: string, errors: string[]} errors: one per failed account or chunk
     */
    public function fetchLabelPdf(array $shipmentIdsByApiKey, $positions = 1): array
    {
        $pdfs        = [];
        $chunkErrors = [];

        $errors = $this->perKey(
            array_map([$this, 'normalizeIds'], $shipmentIdsByApiKey),
            'fetch labels',
            function (string $apiKey, array $shipmentIds) use (&$pdfs, &$chunkErrors, $positions): void {
                // The third argument is the PSR client the service sends with; LabelHttpClient
                // exists so a non-PDF answer is visible, since the SDK discards the body it refused.
                $httpClient = $this->labelHttpClient ?? new LabelHttpClient();
                $service    = $this->tagged($apiKey, static function (string $key, $client) use ($httpClient) {
                    return new ShipmentLabelsService($key, $client, $httpClient);
                });

                // Chunked for the same reason the query is: every id goes into one path segment.
                // LABEL_CHUNK_SIZE is a multiple of four, so an A4 sheet's four positions still
                // fall on the same sheet as they would unchunked.
                foreach (array_chunk($shipmentIds, self::LABEL_CHUNK_SIZE) as $chunk) {
                    try {
                        $pdfs[] = $service->setPdfOfLabels($chunk, $positions);
                    } catch (Throwable $e) {
                        Logger::warning('MyParcel export: could not fetch labels for one chunk', LogContext::of($e));
                        $chunkErrors[] = (string) __(
                            '%1 labels could not be fetched. %2',
                            count($chunk),
                            $e->getMessage()
                        );
                    }
                }
            }
        );

        $errors = array_merge($errors, $chunkErrors);

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
     * Create return shipments against each parent shipment's own account, each mailed to the customer.
     * Each row must carry its parent id as `reference_identifier`: the answer names no parent.
     *
     * @param array<string,array<int,array>> $rowsByApiKey rows as ReturnShipmentService takes them
     *
     * @return array{returns: array<string,array<int,int>>, errors: string[]} per account, new
     *         return shipment id => parent shipment id; one error message per failing account
     */
    public function createReturns(array $rowsByApiKey): array
    {
        $returns = [];

        $errors = $this->perKey(
            $rowsByApiKey,
            'create return shipments',
            function (string $apiKey, array $rows) use (&$returns): void {
                $answer = $this->tagged($apiKey, function (string $key, $client) {
                    return new ReturnShipmentService($key, $client, $this->returnHttpClient);
                })->createRelated($rows, true);

                foreach ($answer as $returnId => $parentId) {
                    if (! (int) $parentId) {
                        Logger::warning(
                            'MyParcel export: a return shipment came back without its parent reference',
                            ['return_shipment_id' => (int) $returnId]
                        );

                        continue;
                    }

                    $returns[$apiKey][(int) $returnId] = (int) $parentId;
                }
            }
        );

        return ['returns' => $returns, 'errors' => $errors];
    }

    /**
     * Create one key's shipments in chunks, with one client and one service for all of them.
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
     * Send one chunk, and on a validation rejection send it once more without the orders the API named.
     * The API reports every faulty shipment in one response, so **one** retry is enough. A second
     * rejection is reported, not retried.
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
        // never read as an API rejection: the shipments exist upstream and are billable.
        try {
            $collection = new ShipmentCollection();

            foreach ($chunk as $built) {
                $collection->push($built->getShipment());
            }

            $created = $service->create($collection);
        } catch (Throwable $e) {
            // Earlier chunks stay recorded: they exist upstream and are billable.
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
            static fn(BuiltShipment $built): bool => ! $rejection->blames($built->getIncrementId())
        ));
    }

    /**
     * @param BuiltShipment[] $chunk
     */
    private function reportCollateral(array $chunk, Rejection $rejection, ExportReport $report): void
    {
        foreach ($chunk as $built) {
            if ($rejection->blames($built->getIncrementId())) {
                continue;
            }

            $report->failCollateral($built->getIncrementId(), $rejection->summary(), $rejection->isRefusal());
        }
    }

    /**
     * Turn one batch rejection into a reason per order, and record them.
     *
     * @param BuiltShipment[] $chunk in request order, which is what an index in a pointer refers to
     */
    private function attributeFailure(Throwable $e, array $chunk, ExportReport $report): Rejection
    {
        $body = Rejection::bodyOf($e);

        if ('' !== $body) {
            // By shape, not whole: the API's error text quotes the field it refused, which on an
            // address is consumer data and does not belong in a log. The keys and pointers still
            // make the next divergence from the documented shape visible.
            Logger::warning('MyParcel export: rejection body ' . Rejection::shapeOf($body));
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
     * Record created shipments by reference identifier, never by position: the API answers in any order.
     *
     * @param array<int,string|null> $created shipment id => reference identifier
     * @param BuiltShipment[]        $chunk
     */
    private function recordCreated(array $created, array $chunk, ExportReport $report): void
    {
        $byReference = [];

        foreach ($chunk as $built) {
            $byReference[$built->getReferenceIdentifier()] = $built;
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

            unset($byReference[$built->getReferenceIdentifier()]);

            try {
                $this->persist($built, (int) $shipmentId);
                $report->succeed($built->getIncrementId(), (int) $shipmentId);
            } catch (Throwable $e) {
                // The shipment exists and is billable; only our record of it failed. Said as such,
                // so the admin does not re-export a shipment that is already there.
                Logger::warning(
                    sprintf('MyParcel export: shipment %d created but its id could not be stored', $shipmentId),
                    LogContext::of($e)
                );
                $report->fail($built->getIncrementId(), sprintf(
                    'created as MyParcel shipment %d, but the id could not be stored: %s',
                    $shipmentId,
                    $e->getMessage()
                ));
            }
        }

        // Anything the response did not name did not ship, whatever the call's status was.
        foreach ($byReference as $built) {
            $report->fail($built->getIncrementId(), (string) __('The MyParcel API did not return a shipment for this order.'));
        }
    }

    /**
     * Store the shipment id per chunk, before the next call. This row makes a re-run safe.
     * A track without an entity id is not saved here: core refuses a parentless track, so the
     * observer flow's shipment save persists it moments later.
     */
    private function persist(BuiltShipment $built, int $shipmentId): void
    {
        $this->stamp($built->getTrack(), $shipmentId);

        // A multicollo's remaining rows take the same id: non-zero is what keeps them out of the
        // next export, and updateMagentoTrack() replaces it with each collo's own id once the
        // query response names them.
        foreach ($built->getSpareTracks() as $spare) {
            $this->stamp($spare, $shipmentId);
        }
    }

    private function stamp(Track $track, int $shipmentId): void
    {
        $track->setData(Config::FIELD_MYPARCEL_SHIPMENT_ID, $shipmentId);
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
            $existing = (int) $built->getTrack()->getData(Config::FIELD_MYPARCEL_SHIPMENT_ID);

            if (0 < $existing) {
                $report->succeed($built->getIncrementId(), $existing);
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
            $groups[$built->getApiKey()][] = $built;
        }

        return $groups;
    }

    /** @param BuiltShipment[] $builtShipments */
    private function failAll(array $builtShipments, ExportReport $report, string $reason): void
    {
        foreach ($builtShipments as $built) {
            $report->fail($built->getIncrementId(), $reason);
        }
    }

    /**
     * Run $call per account. An empty group is skipped, and one account's failure costs only its own work.
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
     * Build an SDK service for one account, with the module's user agents.
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

    /** @return int[] deduplicated, reindexed, without empties */
    private function normalizeIds(array $shipmentIds): array
    {
        return IdList::ints($shipmentIds);
    }

    /** Get the chunk size. A value outside 1..100 (a configured 0 loops forever) falls back. */
    private function chunkSize(): int
    {
        return $this->config->getExportChunkSize();
    }
}
