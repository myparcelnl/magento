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
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Source\PaperType;
use MyParcelNL\Magento\Model\Source\ReturnInTheBox;
use MyParcelNL\Magento\Model\Source\SourceItem;
use MyParcelNL\Magento\Observer\NewShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\OrderGridColumns;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\OrderShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Service\Export\LabelPositions;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\Shipment as SdkShipment;
use MyParcelNL\Sdk\Services\MultiCollo\MultiColloShipmentService;
use MyParcelNL\Sdk\Support\Str;
use Throwable;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;

/**
 * One export run: the shipments built from a Magento order or shipment selection, and the tracks
 * they write back.
 *
 * Read tracks through tracksByShipmentId(), never off a Shipment — see the note there.
 */
abstract class MagentoCollection implements MagentoCollectionInterface
{
    public const PATH_MODEL_ORDER_COLLECTION       = OrderCollection::class;
    public const PATH_MODEL_SHIPMENT_COLLECTION    = ShipmentCollection::class;
    public const ERROR_ORDER_HAS_NO_SHIPMENT       = 'No shipment can be made with this order. Shipments can not be created if the status is On Hold or if the product is digital.';
    public const ERROR_ORDER_HAS_NO_SOURCE         = 'Creating shipments via bulk actions is not possible for orders without a source. Go to the details of the order and process the shipment manually.';
    public const DEFAULT_ERROR_ORDER_HAS_NO_SOURCE = 'Source item not found by source code';

    /**
     * What is left of the label description field for the parent's own description: the whole
     * field minus 'Retour ', ' t/m ' and a d-m-Y date. Pinned by a test rather than computed here.
     */
    private const RETURN_DESCRIPTION_PARENT_LENGTH = OrderShipmentOptions::LABEL_DESCRIPTION_MAX_LENGTH - 22;

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
    protected ShapeLookup            $capabilityLookup;

    /** @var array<int,ShipmentBuilder> one per order, built on first use */
    private array $shipmentBuilders = [];

    /**
     * tracksByShipmentId()'s answer for this pass, or null before the first read.
     *
     * One read per write boundary, not one per caller: a grid export asked five times and paid for
     * the same query each time. Cleared by setNewMagentoTrack(), the only thing that adds a row —
     * the export service mutates the Track objects held here in place, so an id it stored is
     * already visible through the memo.
     */
    private ?array $trackMemo = null;
    protected ManagerInterface       $messageManager;
    protected Config                 $config;
    protected LabelPositions         $labelPositions;

    protected array $options
        = [
            'create_track_if_one_already_exist' => true,
            'request_type'                      => 'download',
            'package_type'                      => 'default',
            'carrier'                           => null,
            'positions'                         => null,
            'signature'                         => null,
            'collect'                           => null,
            'receipt_code'                      => null,
            'only_recipient'                    => null,
            'priority_delivery'                 => null,
            'return'                            => null,
            'large_format'                      => null,
            'age_check'                         => null,
            'insurance'                         => null,
            'label_amount'                      => NewShipment::DEFAULT_LABEL_AMOUNT,
            'digital_stamp_weight'              => null,
            'return_in_the_box'                 => false,
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
        $this->capabilityLookup = $objectManager->get(ShapeLookup::class);

        $this->setSourceItemWhenInventoryApiEnabled();
    }

    /**
     * Set options from POST or GET variables
     *
     * @return self
     */
    public function setOptionsFromParameters()
    {
        // If options isset
        foreach (array_keys($this->options) as $option) {
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

        // A paper size only arrives from the modal, where the admin picked one. Without it — the
        // grid's direct action, a row action — the configured paper type decides, or every such
        // print would be A6 whatever the setting says.
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

        $returnInTheBox = $this->config->getGeneralConfig('print/return_in_the_box');
        if (ReturnInTheBox::NO_OPTIONS === $returnInTheBox || ReturnInTheBox::EQUAL_TO_SHIPMENT === $returnInTheBox) {
            $this->options['return_in_the_box'] = $returnInTheBox;
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
     * For a caller that is not the admin form: the defaults above are a mass action's, and a
     * background job has no business inheriting them.
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
     * Every track of the collection's shipments, grouped by shipment id, in one query.
     *
     * Read fresh on every call, and never off $shipment->getAllTracks(): a Shipment caches its track
     * collection in a private field with no way to reset it, so a shipment object that outlives a
     * track write answers with the tracks as they were — and a track & trace link missed on a cron
     * pass is missed for good. Reading tracks here instead is what lets the shipments collection be
     * loaded once.
     *
     * Take the map once above a loop: every iteration asks about a different shipment, and the
     * tracks a loop creates it holds itself.
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
     * Forget what was read for the collection being replaced. Every setter that swaps the
     * collection out owes this call: the memo is keyed by shipment id, so left standing it answers
     * the new collection with the old one's rows.
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

        // Only the API's own failures are rendered here — build failures were shown where they
        // happened, so that every caller of setNewMyParcelTracks() sees them, not only this one.
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
     * The Magento shipments that actually carry a MyParcel shipment id, for the page that will fetch
     * their labels. Order ids would be too wide: after an export in which nothing shipped, they
     * would still claim labels.
     *
     * A selected order's earlier shipments are included on purpose. Reprint depends on it:
     * setNewMyParcelTracks() skips tracks that already carry an id, so this scan is the only thing
     * that finds them and a selection of exported orders would otherwise print nothing.
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
     * A return label per exported shipment, mailed to the customer, against that shipment's own
     * account — the consignment path used the first order's key for all of them.
     */
    public function sendReturnLabelMails(): self
    {
        $idsByApiKey = $this->getMyparcelConsignmentIdsByApiKey();
        $latest      = $this->exportService->fetchLatest($idsByApiKey);
        $rows        = [];

        foreach ($idsByApiKey as $apiKey => $shipmentIds) {
            foreach ($shipmentIds as $shipmentId) {
                $shipment = $latest[$shipmentId] ?? null;

                if (null === $shipment) {
                    continue;
                }

                $rows[$apiKey][] = ['parent' => (int) $shipmentId, 'carrier' => $shipment->getCarrier()];
            }
        }

        foreach ($this->exportService->createReturns($rows, true) as $error) {
            $this->messageManager->addErrorMessage($error);
        }

        return $this;
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
                    // The order is named here, not by the builder — one prefix, one owner.
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

    /**
     * The shipment as a single multicollo, or null when this order must ship as separate colli.
     *
     * splitShipment() clones, divides the weight and fills secondary_shipments; it takes no API key
     * and throws on a quantity of 1, which the guard excludes.
     */
    public function asMultiCollo(BuiltShipment $built, int $quantity): ?BuiltShipment
    {
        if (1 >= $quantity || ! $this->canUseMultiCollo($built->shipment(), $built->apiKey())) {
            return null;
        }

        return $built->withShipment(
            (new MultiColloShipmentService())->splitShipment($built->shipment(), $quantity)
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
     * One Shipment per collo, each built afresh against its *own* track row. Sharing the first track
     * would make persist() write every returned shipment id onto one row, leaving the other rows
     * id-less — unprintable, and re-exported as new billable shipments by the next mass action.
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
     * A return label alongside each outbound shipment, created against that shipment's own account.
     *
     * The v11 call takes rows naming a parent shipment id, so the returns can only be made after the
     * outbound create has answered. NO_OPTIONS still means a bare label — the options are simply
     * omitted rather than set to false.
     */
    public function addReturnInTheBox(string $returnOptions): void
    {
        $rows = [];

        foreach ($this->builtShipments as $built) {
            $shipmentId = (int) $built->track()->getData('myparcel_consignment_id');

            if (0 === $shipmentId) {
                continue;
            }

            $row = [
                'parent'  => $shipmentId,
                'carrier' => $built->shipment()->getCarrier(),
            ];

            if (ReturnInTheBox::NO_OPTIONS !== $returnOptions) {
                $row['options'] = ['label_description' => $this->returnLabelDescription($built)];
            }

            $rows[$built->apiKey()][] = $row;
        }

        foreach ($this->exportService->createReturns($rows, false) as $error) {
            $this->messageManager->addErrorMessage($error);
        }
    }

    /**
     * Only the parent description is truncated, so the validity date always survives. The old
     * wording could not fit at all — it was 46 characters before the parent description was even
     * added, against a 45-character field, and nothing on this path clips it.
     */
    private function returnLabelDescription(BuiltShipment $built): string
    {
        $parentDescription = (string) $built->shipment()->getOptions()->getLabelDescription();

        return implode(' ', array_filter([
            'Retour',
            Str::limit($parentDescription, self::RETURN_DESCRIPTION_PARENT_LENGTH),
            't/m',
            date('d-m-Y', strtotime('+ 28 days')),
        ]));
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

        // The shipment id is already on each track — the export service wrote it per chunk — so this
        // only refreshes status and barcode. Nothing is paired by position any more.
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
                    $magentoTrack->save();
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
     * A track per multicollo collo beyond the first.
     *
     * A multicollo is created as one shipment carrying secondary_shipments, and the module keeps
     * one Track for the whole of it — the SDK's create response drops the secondaries, so colli
     * 2..N never had an id to store. The query response does carry them, each a full shipment with
     * its own id, barcode and status, so the rows are made here instead. From the next run on
     * each is an ordinary track and refreshes itself.
     *
     * @param array<int,array{shipment: Order\Shipment, parentId: int, collo: object}> $secondary
     * @param array<int,true>                                                          $claimedIds ids already on a track
     * @param array<int,Track[]>                                                       $spare rows persist() parked on
     *        the parent's id, by parent id — reused before a row is added, so an order keeps the
     *        label_amount rows it was given rather than gaining one per collo
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
     * The consumer portal link a collo is missing.
     *
     * The api fills link_consumer_portal in only for a shipment asked for by id, never for one
     * nested in a parent's secondary_shipments — so a collo reaches here without it. Each now has a
     * track carrying its own id, which is all the grouping below needs to ask about it.
     *
     * Only the colli are asked for: their parents were read moments ago and nothing about them has
     * changed. A collo whose nested entry did carry a link is not here at all, so the day the api
     * starts sending them this call stops happening.
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
     * The three fields a MyParcel shipment gives its track. Shared so a collo and the shipment it
     * belongs to can never be written differently.
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
     * @return self
     */
    public function addReturnShipments(): self
    {
        $returnInTheBoxOptions = $this->options['return_in_the_box'] ?? null;

        if ($returnInTheBoxOptions && $this->builtShipments) {
            $this->addReturnInTheBox($returnInTheBoxOptions);
        }

        return $this;
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
     * @return array<string,int[]> MyParcel shipment ids grouped by resolved API key
     */
    /**
     * @param array<int,Track[]>|null $tracks the caller's own read, when it already holds one
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

        $capabilities = $this->capabilityLookup->forApiKeyShape($apiKey, $country, $packageType);

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
