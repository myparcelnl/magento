<?php

declare(strict_types=1);
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Source\PaperType;
use MyParcelNL\Magento\Model\Source\SourceItem;
use MyParcelNL\Magento\Observer\NewShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\OrderGridColumns;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Export\LabelPositions;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitiesLookup;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesCarrierReturns;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\Shipment as SdkShipment;
use MyParcelNL\Sdk\Services\MultiCollo\MultiColloShipmentService;
use Throwable;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;
use MyParcelNL\Magento\Service\TrackTrace\ReturnIds;

/**
 * One export run: the shipments built from a Magento order or shipment selection, and the tracks
 * they write back.
 *
 * Read tracks through tracksByShipmentId(), never off a Shipment. The note there says why.
 */
abstract class MagentoCollection implements MagentoCollectionInterface
{
    public const PATH_MODEL_ORDER_COLLECTION       = OrderCollection::class;
    public const PATH_MODEL_SHIPMENT_COLLECTION    = ShipmentCollection::class;
    public const ERROR_ORDER_HAS_NO_SHIPMENT       = 'No shipment can be made with this order. Shipments can not be created if the status is On Hold or if the product is digital.';
    public const ERROR_ORDER_HAS_NO_SOURCE         = 'Creating shipments via bulk actions is not possible for orders without a source. Go to the details of the order and process the shipment manually.';
    public const DEFAULT_ERROR_ORDER_HAS_NO_SOURCE = 'Source item not found by source code';

    private const PATH_ORDER_TRACK            = '\Magento\Sales\Model\Order\Shipment\Track';
    private const PATH_MANAGER_INTERFACE      = '\Magento\Framework\Message\ManagerInterface';
    private const PATH_ORDER_TRACK_COLLECTION = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';


    /** @var BuiltShipment[] built and ready to send; replaces MyParcelCollection */
    public array                     $builtShipments = [];
    protected ShipmentExportService  $exportService;
    public ?RequestInterface         $request = null;
    protected Manager                $moduleManager;
    protected SourceItem             $sourceItem;
    protected TrackSender            $trackSender;
    protected ObjectManagerInterface $objectManager;
    protected Track                  $modelTrack;
    protected OrderGridColumns       $gridColumns;
    protected ShipmentApiProvider    $apiProvider;
    protected UserAgent              $userAgent;
    protected CapabilitiesLookup     $capabilityLookup;

    /** @var array<int,ShipmentBuilder> one per order, built on first use */
    private array $shipmentBuilders = [];

    /**
     * tracksByShipmentId()'s answer for this pass, or null before the first read.
     *
     * Only setNewMagentoTrack() adds a row, so only it clears this. The export service changes the
     * Track objects held here in place, so an id it stores is already visible through the memo.
     */
    private ?array $trackMemo = null;

    protected ManagerInterface       $messageManager;
    protected Config                 $config;
    protected LabelPositions         $labelPositions;

    /** Every toggle in ShipmentOption::DEFINITIONS is added by setOptionsFromParameters(). */
    protected array $options
        = [
            'create_track_if_one_already_exist' => true,
            'request_type'                      => 'download',
            'package_type'                      => 'default',
            'carrier'                           => null,
            'positions'                         => null,
            'insurance'                         => null,
            'label_amount'                      => NewShipment::DEFAULT_LABEL_AMOUNT,
            'digital_stamp_weight'              => null,
            'same_day_delivery'                 => false,
        ];

    /**
     * @param ObjectManagerInterface $objectManager
     * @param null                   $request
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
                               $request = null
    )
    {
        $this->objectManager  = $objectManager;
        $this->moduleManager  = $objectManager->get(Manager::class);
        $this->request        = $request;
        $this->trackSender    = $objectManager->get(TrackSender::class);
        $this->config         = $objectManager->get(Config::class);
        $this->modelTrack     = $objectManager->create(self::PATH_ORDER_TRACK);
        $this->messageManager = $objectManager->create(self::PATH_MANAGER_INTERFACE);
        $this->exportService  = $objectManager->get(ShipmentExportService::class);
        $this->labelPositions = $objectManager->get(LabelPositions::class);
        $this->gridColumns    = $objectManager->get(OrderGridColumns::class);
        $this->apiProvider    = $objectManager->get(ShipmentApiProvider::class);
        $this->userAgent      = $objectManager->get(UserAgent::class);
        $this->capabilityLookup = $objectManager->get(CapabilitiesLookup::class);

        $this->setSourceItemWhenInventoryApiEnabled();
    }

    /**
     * Set options from POST or GET variables
     *
     * @return self
     */
    public function setOptionsFromParameters()
    {
        foreach (array_keys($this->options + array_fill_keys(ShipmentOption::having('toggle'), null)) as $option) {
            if ($this->request->getParam('mypa_' . $option) === null) {
                if ($this->request->getParam('mypa_extra_options_checkboxes_in_form') === null) {
                    // Use default options
                    $this->options[$option] = null;
                } else {
                    // Checkbox isset but false
                    $this->options[$option] = false;
                }
            } else {
                $this->options[$option] = $this->request->getParam('mypa_' . $option);
            }
        }

        $label_amount = $this->request->getParam('mypa_label_amount') ?? NewShipment::DEFAULT_LABEL_AMOUNT;

        if ($label_amount) {
            $this->options['label_amount'] = $label_amount;
        }

        // Only the modal sends a paper size. Without it (the grid's direct action, a row action),
        // the configured paper type decides, or every such print is A6 whatever the setting says.
        $paperSize = $this->request->getParam('mypa_paper_size');

        if (null === $paperSize) {
            $this->options['positions'] = $this->labelPositions->configured();
        } elseif (PaperType::A4 !== $paperSize) {
            $this->options['positions'] = null;
        }

        if ($this->request->getParam('mypa_request_type') === null) {
            $this->options['request_type'] = 'download';
        }

        if ($this->request->getParam('mypa_request_type') !== 'concept') {
            $this->options['create_track_if_one_already_exist'] = false;
        }

        return $this;
    }

    /**
     * Get all options
     *
     * @return array
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get option by key
     *
     * @param $option
     *
     * @return mixed
     */
    public function getOption($option)
    {
        return $this->options[$option];
    }

    /**
     * Set one transport setting, for a caller that is not the admin form.
     *
     * @param mixed $value
     */
    public function setOption(string $option, $value): self
    {
        $this->options[$option] = $value;

        return $this;
    }

    /**
     * Create new Magento Track
     *
     * @param Order\Shipment $shipment
     *
     * @return Track
     * @throws Exception
     */
    protected function setNewMagentoTrack($shipment)
    {
        $track = ShipmentBuilder::newTrackFor($this->objectManager, $shipment);
        $track->save();

        // The memo no longer describes the rows on disk.
        $this->trackMemo = null;

        return $track;
    }

    /**
     * Get every track of the collection's shipments, grouped by shipment id, in one query.
     *
     * Never read $shipment->getAllTracks(): a Shipment caches its tracks with no way to reset them,
     * so after a track write it answers with stale tracks, and a cron pass misses the link for good.
     *
     * @return array<int,Track[]>
     */
    protected function tracksByShipmentId(): array
    {
        if (null !== $this->trackMemo) {
            return $this->trackMemo;
        }

        return $this->trackMemo = $this->readTracksByShipmentId();
    }

    /**
     * Forget the tracks read for the collection being replaced.
     *
     * Every setter that swaps the collection must call this: the memo is keyed by shipment id and
     * otherwise answers the new collection with the old rows.
     */
    protected function forgetTracks(): void
    {
        $this->trackMemo = null;
    }

    /** @return array<int,Track[]> */
    private function readTracksByShipmentId(): array
    {
        $shipmentIds = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            $shipmentIds[] = (int) $shipment->getId();
        }

        if (! $shipmentIds) {
            return [];
        }

        /* @var Collection $collection */
        $collection = $this->objectManager->create(self::PATH_ORDER_TRACK_COLLECTION);
        $collection->addAttributeToFilter('parent_id', ['in' => $shipmentIds]);
        MyParcelTracks::scopeCollection($collection);

        $grouped = [];

        foreach ($collection->getItems() as $track) {
            $grouped[(int) $track->getParentId()][] = $track;
        }

        return $grouped;
    }

    /**
     * Get MyParcel Track from Magento Track
     *
     * @param Track $magentoTrack
     *
     * @return BuiltShipment
     * @throws LocalizedException
     */
    protected function buildShipment($magentoTrack, int $colloNumber = 1): BuiltShipment
    {
        $magentoShipment = $magentoTrack->getShipment();
        $orderId         = (int) $magentoShipment->getOrderId();

        // One builder per order, not per track. Its DefaultOptions json_decodes the order's
        // delivery-options column and memoises the carrier settings, and a five-collo order was
        // paying for both five times. getOrder() loads through the repository, so it is only
        // reached on a miss.
        if (! isset($this->shipmentBuilders[$orderId])) {
            $this->shipmentBuilders[$orderId] = new ShipmentBuilder(
                $this->objectManager,
                $magentoShipment->getOrder()
            );
        }

        return $this->shipmentBuilders[$orderId]->build($magentoTrack, $this->options, $colloNumber);
    }

    /**
     * @return self
     * @throws Exception
     */
    public function createMyParcelConcepts(): self
    {
        if (! $this->builtShipments) {
            // Nothing built is not the same as nothing to do: a selection of orders that already
            // carry labels is a reprint, and warning there reads as a failure.
            if (! $this->getMyparcelConsignmentIdsByApiKey()) {
                $this->messageManager->addWarningMessage(__('No MyParcel shipments to process.'));
            }

            return $this;
        }

        // Only the API's own failures show here. Build failures showed where they happened, so
        // every caller of setNewMyParcelTracks() sees them.
        $report = $this->exportService->createConcepts($this->builtShipments);

        foreach ($report->failureMessages() as $message) {
            $this->messageManager->addErrorMessage($message);
        }

        return $this;
    }

    public function getExportService(): ShipmentExportService
    {
        return $this->exportService;
    }

    /**
     * Get the Magento shipments that carry a MyParcel shipment id, for the label page.
     *
     * A selected order's earlier shipments are included on purpose: reprint depends on them,
     * because setNewMyParcelTracks() skips tracks that already carry an id.
     *
     * @return int[] Magento shipment entity ids
     */
    public function getExportedShipmentIds(): array
    {
        $shipmentIds = [];
        $tracks      = $this->tracksByShipmentId();

        foreach ($this->getShipmentsCollection() as $shipment) {
            foreach ($tracks[(int) $shipment->getId()] ?? [] as $track) {
                if (0 < (int) $track->getData('myparcel_consignment_id')) {
                    $shipmentIds[] = (int) $shipment->getEntityId();
                    break;
                }
            }
        }

        return array_values(array_unique($shipmentIds));
    }


    /**
     * Mail a return label per exported shipment to the customer, against that shipment's own account.
     *
     * @return bool whether any account was asked and answered. False is not "nothing to do": a
     *              failed lookup leaves its shipments out of the answer.
     */
    public function sendReturnLabelMails(): bool
    {
        $idsByApiKey = $this->getMyparcelConsignmentIdsByApiKey();
        $lookup      = $this->exportService->fetchLatestWithErrors($idsByApiKey);
        $rows        = [];
        $reported    = false;

        foreach ($lookup['errors'] as $error) {
            $this->messageManager->addErrorMessage($error);
        }

        foreach ($idsByApiKey as $apiKey => $shipmentIds) {
            foreach ($shipmentIds as $shipmentId) {
                $shipment = $lookup['shipments'][$shipmentId] ?? null;

                if (null === $shipment) {
                    // A lookup error already names its account; only a silent miss needs a message.
                    if (! in_array((string) $apiKey, $lookup['failedApiKeys'], true)) {
                        $reported = true;
                        $this->messageManager->addErrorMessage($this->shipmentMessage(
                            (int) $shipmentId,
                            static fn (string $name) => __('MyParcel does not know shipment %1 for the API key of this store.', $name)
                        ));
                    }

                    continue;
                }

                // The SDK throws on a carrier outside this enum, which fails the whole account.
                if (! in_array((int) $shipment->getCarrierId(), RefTypesCarrierReturns::getAllowableEnumValues(), true)) {
                    $reported = true;
                    $this->messageManager->addErrorMessage($this->shipmentMessage(
                        (int) $shipmentId,
                        static fn (string $name) => __('The carrier of shipment %1 does not support return labels.', $name),
                        $shipment->getBarcode()
                    ));

                    continue;
                }

                // getCarrierId(), not getCarrier(): a queried shipment is a ShipmentDefsShipment,
                // which spells it differently from the one the export builds.
                $rows[$apiKey][] = $this->returnRow(
                    (int) $shipmentId,
                    (int) $shipment->getCarrierId(),
                    $shipment->getRecipient()
                );
            }
        }

        if (! $rows) {
            if (! $lookup['errors'] && ! $reported) {
                $this->messageManager->addErrorMessage(
                    __('No MyParcel shipments were found to make a return label for.')
                );
            }

            return false;
        }

        $answer = $this->exportService->createReturns($rows);

        foreach ($answer['errors'] as $error) {
            $this->messageManager->addErrorMessage($error);
        }

        $this->recordReturns(array_replace([], ...array_values($answer['returns'])));

        // createReturns() reports one error per failing account, so an error for every account it
        // was given means nothing was mailed.
        return count($answer['errors']) < count($rows);
    }

    /**
     * An error about one MyParcel shipment, prefixed with its order and naming it by barcode.
     *
     * The phrase comes in as a callable so its __() keeps a literal, which i18n:collect-phrases needs.
     *
     * @param callable(string): \Magento\Framework\Phrase $phrase  receives the barcode, or the id without one
     * @param string|null                                $barcode the API's, when it answered; else the track's
     */
    private function shipmentMessage(int $shipmentId, callable $phrase, ?string $barcode = null): string
    {
        $track    = $this->firstTrackByMyParcelId()[$shipmentId] ?? null;
        $shipment = $track ? $this->getShipmentsCollection()->getItemById((int) $track->getData('parent_id')) : null;
        $message  = (string) $phrase((string) ($barcode ?: ($track ? $track->getData('track_number') : '') ?: $shipmentId));

        return $shipment ? sprintf('%s: %s', $shipment->getOrder()->getIncrementId(), $message) : $message;
    }

    /**
     * Only the first track per id: colli can share their parent's id until a query names them.
     *
     * @return array<int,Track> keyed by MyParcel shipment id
     */
    private function firstTrackByMyParcelId(): array
    {
        $trackOf = [];

        foreach ($this->tracksByShipmentId() as $tracks) {
            foreach ($tracks as $track) {
                $shipmentId           = (int) $track->getData('myparcel_consignment_id');
                $trackOf[$shipmentId] = $trackOf[$shipmentId] ?? $track;
            }
        }

        return $trackOf;
    }

    /**
     * Get one row for the return-shipments endpoint.
     *
     * The API refuses a row without email and name. They come from the parent's recipient, because
     * the return goes back to that person. The answer echoes only reference_identifier, so it repeats
     * the parent id.
     *
     * @param object|null $recipient any recipient model carrying getEmail() and getPerson(); the
     *                               queried and the built shipment each have their own class
     */
    private function returnRow(int $parentShipmentId, int $carrier, $recipient): array
    {
        return [
            'parent'               => $parentShipmentId,
            'reference_identifier' => $parentShipmentId,
            'carrier'              => $carrier,
            'email'                => null === $recipient ? '' : (string) $recipient->getEmail(),
            'name'                 => null === $recipient ? '' : (string) $recipient->getPerson(),
        ];
    }

    /**
     * Adds each return shipment id to the track of the shipment it was made for.
     *
     * @param array<int,int> $returns return shipment id => parent shipment id
     */
    private function recordReturns(array $returns): void
    {
        if (! $returns) {
            return;
        }

        $trackOf = $this->firstTrackByMyParcelId();
        $changed = [];

        foreach ($returns as $returnId => $parentId) {
            $track = $trackOf[$parentId] ?? null;

            if (null === $track) {
                Logger::warning('MyParcel: no track found for a return shipment', [
                    'return_shipment_id' => $returnId,
                    'parent_shipment_id' => $parentId,
                ]);

                continue;
            }

            $track->setData(
                ReturnIds::FIELD,
                json_encode(array_merge(ReturnIds::of($track), [$returnId]))
            );
            $changed[$parentId] = $track;
        }

        foreach ($changed as $track) {
            try {
                $track->save();
            } catch (Throwable $e) {
                // The mail is already sent; losing the record is the smaller problem.
                Logger::warning('MyParcel: could not record a return shipment on its track', LogContext::of($e));
            }
        }
    }

    /**
     * Add MyParcel Track from Magento Track
     *
     * @return self
     * @throws Exception
     */
    public function setNewMyParcelTracks(): self
    {
        $shipments = $this->getShipmentsCollection();
        $tracks    = $this->tracksByShipmentId();

        $multiColloConsignments = []; // parent shipment id => built shipment + collo count
        /**
         * @var Order\Shipment $shipment
         * @var Track          $magentoTrack
         */
        foreach ($shipments as $shipment) {
            $magentoTracks = $tracks[(int) $shipment->getId()] ?? [];

            foreach ($magentoTracks as $magentoTrack) {
                if ($magentoTrack->getData('myparcel_consignment_id')
                    || Carrier::CODE !== $magentoTrack->getCarrierCode()
                ) {
                    continue;
                }

                $parentId = $magentoTrack->getData('parent_id');

                if (isset($multiColloConsignments[$parentId])) {
                    $multiColloConsignments[$parentId]['colli']++;
                    // Kept, not just counted: each collo must pair with its own track row, or every
                    // returned shipment id lands on the first row and the others stay id-less.
                    $multiColloConsignments[$parentId]['tracks'][] = $magentoTrack;
                    continue;
                }

                try {
                    $built = $this->buildShipment($magentoTrack);
                } catch (\Throwable $e) {
                    // The order is named here, not by the builder: one prefix, one owner.
                    $incrementId = (string) $shipment->getOrder()->getIncrementId();
                    $this->messageManager->addErrorMessage(sprintf('%s: %s', $incrementId, $e->getMessage()));
                    continue;
                }

                $multiColloConsignments[$parentId] = [
                    'built'  => $built,
                    'tracks' => [$magentoTrack],
                    'colli'  => 1,
                ];
            }
        }

        return $this->addGroupedShipments($multiColloConsignments);
    }

    /** Get the shipment as one multicollo, or null when this order must ship as separate colli. */
    public function asMultiCollo(BuiltShipment $built, int $quantity): ?BuiltShipment
    {
        if (1 >= $quantity || ! $this->canUseMultiCollo($built->getShipment(), $built->getApiKey())) {
            return null;
        }

        return $built->withShipment(
            (new MultiColloShipmentService())->splitShipment($built->getShipment(), $quantity)
        );
    }

    /**
     * @param array<int,array{built:BuiltShipment,tracks:Track[],colli:int}> $multiColloConsignments
     */
    protected function addGroupedShipments(array $multiColloConsignments): self
    {
        foreach ($multiColloConsignments as $group) {
            /** @var BuiltShipment $built */
            $built    = $group['built'];
            $quantity = (int) $group['colli'];

            $multiCollo = $this->asMultiCollo($built, $quantity);

            if (null !== $multiCollo) {
                // The colli beyond the first ship inside this one shipment, but their track rows
                // already exist. Handed along so persist() can claim them; left id-less they would
                // be exported again as new billable shipments by the next mass action.
                $this->builtShipments[] = $multiCollo->withSpareTracks(array_slice($group['tracks'], 1));
                continue;
            }

            $this->addShipmentMultipleTimes($built, $group['tracks']);
        }

        return $this;
    }

    /**
     * Add one Shipment per collo, each built against its *own* track row.
     *
     * A shared track makes persist() write every id onto one row. The other rows then stay id-less:
     * unprintable, and exported again as new billable shipments by the next mass action.
     *
     * @param Track[] $tracks the shipment's id-less tracks, first one already built
     */
    protected function addShipmentMultipleTimes(BuiltShipment $built, array $tracks): void
    {
        $this->builtShipments[] = $built;

        foreach (array_slice($tracks, 1) as $index => $track) {
            try {
                $this->builtShipments[] = $this->buildShipment($track, $index + 2);
            } catch (Throwable $e) {
                $this->messageManager->addErrorMessage($e->getMessage());

                return;
            }
        }
    }

    /**
     * @return self
     * @throws Exception
     */
    public function updateMagentoTrack(): self
    {
        // One read for both uses: no track is written between them, so the second load answered
        // with the same rows.
        $tracks = $this->tracksByShipmentId();

        // The export service already wrote the shipment id on each track, so this only refreshes
        // status and barcode.
        $latest = $this->exportService->fetchLatest($this->getMyparcelConsignmentIdsByApiKey($tracks));

        $claimedIds = [];
        $secondary  = [];
        $spare      = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            $parentSeen = [];

            foreach ($tracks[(int) $shipment->getId()] ?? [] as $magentoTrack) {
                $shipmentId = (int) $magentoTrack->getData('myparcel_consignment_id');

                if (0 !== $shipmentId) {
                    // A second row on the same id is a multicollo collo that persist() parked here.
                    // It waits for its own id rather than being refreshed as the parent.
                    if (isset($parentSeen[$shipmentId])) {
                        $spare[$shipmentId][] = $magentoTrack;
                        continue;
                    }

                    $parentSeen[$shipmentId] = true;
                    $claimedIds[$shipmentId] = true;
                }

                $myParcelShipment = $latest[$shipmentId] ?? null;

                if (null === $myParcelShipment) {
                    continue;
                }

                $this->writeShipmentToTrack($magentoTrack, $myParcelShipment);

                // A refresh that brought nothing new writes nothing: writeShipmentToTrack() only
                // sets a field the response actually carried, and a save of an unchanged model is
                // still a transaction.
                if ($magentoTrack->hasDataChanges()) {
                    try {
                        $magentoTrack->save();
                    } catch (Throwable $e) {
                        // The cron selects on status without excluding a row that failed before, so
                        // one unsaveable track would take every order behind it down with it, tick
                        // after tick. Skip the row instead; its colli wait for the parent to save.
                        Logger::warning('MyParcel: could not update one track', LogContext::of($e));

                        continue;
                    }
                }

                // Collected, not created here: setNewMagentoTrack() saves, and adding rows to a
                // track collection that is being iterated is asking for trouble.
                $colli = $myParcelShipment->getSecondaryShipments();

                // secondary_shipments is typed mixed on the nested resource, so never foreach blind.
                foreach (is_array($colli) ? $colli : [] as $collo) {
                    $secondary[] = ['shipment' => $shipment, 'parentId' => $shipmentId, 'collo' => $collo];
                }
            }
        }

        $this->fillColloLinks($this->addSecondaryShipmentTracks($secondary, $claimedIds, $spare));

        return $this->updateOrderGrid();
    }

    /**
     * Add a track per multicollo collo beyond the first.
     *
     * The SDK's create response drops secondary_shipments, so colli 2..N get their ids only from the
     * query response. From the next run on each is an ordinary track.
     *
     * @param array<int,array{shipment: Order\Shipment, parentId: int, collo: object}> $secondary
     * @param array<int,true>                                                          $claimedIds ids already on a track
     * @param array<int,Track[]>                                                       $spare rows persist() parked on
     *        the parent's id, by parent id. They are used first, so an order keeps its label_amount
     *        rows and does not get one more per collo.
     *
     * @return array<int,array{shipment: Order\Shipment, track: Track}> the rows that came out of this
     *         still without a link, keyed by collo id
     */
    private function addSecondaryShipmentTracks(array $secondary, array $claimedIds, array $spare = []): array
    {
        $needLink = [];

        foreach ($secondary as $entry) {
            $collo   = $entry['collo'];
            $colloId = (int) $collo->getId();

            if (0 === $colloId || isset($claimedIds[$colloId])) {
                continue;
            }

            $parentId     = (int) ($entry['parentId'] ?? 0);
            $magentoTrack = empty($spare[$parentId])
                ? $this->setNewMagentoTrack($entry['shipment'])
                : array_shift($spare[$parentId]);

            $magentoTrack->setData('myparcel_consignment_id', $colloId);
            $this->writeShipmentToTrack($magentoTrack, $collo);
            $magentoTrack->save();

            $claimedIds[$colloId] = true;

            if ('' === (string) $magentoTrack->getData('myparcel_tracktrace_url')) {
                $needLink[$colloId] = ['shipment' => $entry['shipment'], 'track' => $magentoTrack];
            }
        }

        return $needLink;
    }

    /**
     * Fill the consumer portal link a collo is missing.
     *
     * The API sets link_consumer_portal only on a shipment asked for by id, never on one nested in
     * secondary_shipments. So this asks for the colli by their own ids.
     *
     * @param array<int,array{shipment: Order\Shipment, track: Track}> $colli keyed by collo id
     */
    private function fillColloLinks(array $colli): void
    {
        if (! $colli) {
            return;
        }

        $tracksByShipmentId = [];

        foreach ($colli as $entry) {
            $tracksByShipmentId[(int) $entry['shipment']->getId()][] = $entry['track'];
        }

        $latest = $this->exportService->fetchLatest(
            $this->getMyparcelConsignmentIdsByApiKey($tracksByShipmentId)
        );

        foreach ($colli as $colloId => $entry) {
            $myParcelShipment = $latest[$colloId] ?? null;

            if (null === $myParcelShipment) {
                continue;
            }

            $this->writeShipmentToTrack($entry['track'], $myParcelShipment);

            if ($entry['track']->hasDataChanges()) {
                $entry['track']->save();
            }
        }
    }

    /**
     * Write the three fields a MyParcel shipment gives its track, for a collo and its parent alike.
     *
     * @param Track  $magentoTrack
     * @param object $myParcelShipment ShipmentDefsShipment or SecondaryShipmentResource
     */
    private function writeShipmentToTrack(Track $magentoTrack, object $myParcelShipment): void
    {
        if ($myParcelShipment->getStatus()) {
            $magentoTrack->setData('myparcel_status', $myParcelShipment->getStatus());
        }

        if ($myParcelShipment->getBarcode()) {
            $magentoTrack->setTrackNumber($myParcelShipment->getBarcode());
        }

        // A concept has no link yet, and the spec allows an empty string where a uri belongs.
        // Neither may blank a link already stored.
        if ($myParcelShipment->getLinkConsumerPortal()) {
            $magentoTrack->setData(
                'myparcel_tracktrace_url',
                $myParcelShipment->getLinkConsumerPortal()
            );
        }
    }

    /**
     * The page's order ids, not its orders: nothing is loaded here, and an order with two shipments
     * is written once.
     */
    protected function updateOrderGrid(): self
    {
        $orderIds = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            $orderIds[] = (int) $shipment->getOrderId();
        }

        $this->gridColumns->writeFor(array_values(array_unique($orderIds)));

        return $this;
    }

    abstract protected function getShipmentsCollection(): ShipmentCollection;

    /**
     * @param array<int,Track[]>|null $tracks the caller's own read, when it already holds one
     *
     * @return array<string,int[]> MyParcel shipment ids grouped by resolved API key
     */
    public function getMyparcelConsignmentIdsByApiKey(?array $tracks = null): array
    {
        return $this->apiProvider->consignmentIdsByApiKey(
            $this->getShipmentsCollection(),
            $tracks ?? $this->tracksByShipmentId()
        );
    }

    /**
     * Whether this shipment may be sent as one multicollo shipment rather than as separate ones.
     *
     * The rule is unchanged; only where the facts come from is. A v11 Shipment carries no API key,
     * so it is passed alongside, and carrier and package type are ids that have to be named before
     * capabilities can be asked about them.
     */
    public function canUseMultiCollo(SdkShipment $shipment, string $apiKey): bool
    {
        $carrier     = $this->carrierNameOf($shipment);
        $country     = $shipment->getRecipient() ? $shipment->getRecipient()->getCc() : null;
        $options     = $shipment->getOptions();
        $packageType = $options ? PackageType::nameFromIdOrNull($options->getPackageType()) : null;

        if (null === $carrier || null === $country || null === $packageType || '' === $apiKey) {
            return false;
        }

        $capabilities = $this->capabilityLookup->forApiKeyRequest($apiKey, $country, $packageType);

        return 1 < (int) $capabilities->colloMaxFor($carrier, $packageType);
    }

    /** The module's own carrier name, via the Core API name, from the id the Shipment holds. */
    private function carrierNameOf(SdkShipment $shipment): ?string
    {
        $carrierId = $shipment->getCarrier();

        if (! is_int($carrierId)) {
            return null;
        }

        try {
            return ShipmentCarrier::fromV2Name(SdkCarrier::fromId($carrierId));
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Check if the module Magento_InventoryApi is activated.
     * Some customers have removed the Magento_InventoryApi from their system.
     * That causes problems with the Multi Stock Inventory
     *
     * @return void
     */
    private function setSourceItemWhenInventoryApiEnabled(): void
    {
        if (! $this->moduleManager->isEnabled('Magento_InventoryApi')) {
            return;
        }
        $this->sourceItem = $this->objectManager->get(SourceItem::class);
    }
}
