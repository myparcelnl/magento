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
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelNL\Magento\Model\Source\ReturnInTheBox;
use MyParcelNL\Magento\Model\Source\SourceItem;
use MyParcelNL\Magento\Observer\NewShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Model\Shipment\ShipmentBuilder;
use MyParcelNL\Magento\Service\Export\LabelPositions;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository as CapabilitiesRepository;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;
use MyParcelNL\Sdk\Model\Shipment\Carrier as SdkCarrier;
use MyParcelNL\Sdk\Model\Shipment\Shipment as SdkShipment;
use MyParcelNL\Sdk\Services\MultiCollo\MultiColloShipmentService;
use Throwable;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
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
    protected AreaList               $areaList;
    protected ManagerInterface       $messageManager;
    protected Config                 $config;
    protected Weight                 $weight;
    protected LabelPositions         $labelPositions;

    protected array $options
        = [
            'create_track_if_one_already_exist' => true,
            'request_type'                      => 'download',
            'package_type'                      => 'default',
            'carrier'                           => 'postnl',
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
     * @param null                   $areaList
     */
    public function __construct(
        ObjectManagerInterface $objectManager,
                               $request = null,
                               $areaList = null
    )
    {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager  = $objectManager;
        $this->moduleManager  = $objectManager->get(Manager::class);
        $this->request        = $request;
        $this->trackSender    = $objectManager->get(TrackSender::class);
        $this->config         = $objectManager->get(Config::class);
        $this->weight         = $objectManager->get(Weight::class);
        $this->modelTrack     = $objectManager->create(self::PATH_ORDER_TRACK);
        $this->messageManager = $objectManager->create(self::PATH_MANAGER_INTERFACE);
        $this->exportService  = $objectManager->get(ShipmentExportService::class);
        $this->labelPositions = $objectManager->get(LabelPositions::class);

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
        } elseif ('A4' !== $paperSize) {
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
     * Update sales_order table
     *
     * @param $orderId
     *
     * @return array
     */
    public function getHtmlForGridColumns($orderId)
    {
        /**
         * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
         */
        // Temporarily fix to translate in cronjob
        if (! empty($this->areaList)) {
            $areaObject = $this->areaList->getArea(Area::AREA_ADMINHTML);
            $areaObject->load(Area::PART_TRANSLATE);
        }

        return $this->getHtmlForGridColumnsByTracks($this->getTracksCollectionByOrderId($orderId));
    }

    /**
     * @param Track[]|Collection $tracks
     *
     * @return string[]
     */
    public function getHtmlForGridColumnsByTracks($tracks): array
    {
        $data       = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        foreach ($tracks as $track) {
            // Set all Track data in array
            if (null !== $track['myparcel_status']) {
                $data['track_status'][] = __('status_' . $track['myparcel_status']);
            }
            if ($track['track_number']) {
                $data['track_number'][] = $track['track_number'];
            }
        }

        // Create html
        if ($data['track_status']) {
            $columnHtml['track_status'] = implode('<br>', $data['track_status'] ?? []);
        }
        if ($data['track_number']) {
            $columnHtml['track_number'] = json_encode($data['track_number']);
        }

        return $columnHtml;
    }

    /**
     * Check if track already exists
     *
     * @param Shipment $shipment
     *
     * @return bool
     */
    protected function shipmentHasTrack($shipment)
    {
        return $this->getTrackByShipment($shipment)->count() == 0 ? false : true;
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
        /** @var Track $track */
        $track = $this->objectManager->create(Track::class);
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(Carrier::CODE)
            ->setTitle(Config::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY)
            ->save()
        ;

        return $track;
    }

    /**
     * Get all tracks
     *
     * @param Shipment $shipment
     *
     * @return Collection
     */
    protected function getTrackByShipment($shipment)
    {
        /* @var Collection $collection */
        $collection = $this->objectManager->create(self::PATH_ORDER_TRACK_COLLECTION);
        $collection
            ->addAttributeToFilter('parent_id', $shipment->getId())
        ;

        return $collection;
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
        return (new ShipmentBuilder($this->objectManager, $magentoTrack->getShipment()->getOrder()))
            ->build($magentoTrack, $this->options, $colloNumber);
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
     * their labels. Order ids would be too wide: an order shipped in two halves would reprint the
     * older half's labels along with the new ones, and a failed export would still claim labels.
     *
     * @return int[] Magento shipment entity ids
     */
    public function getExportedShipmentIds(): array
    {
        $shipmentIds = [];

        foreach ($this->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getAllTracks() as $track) {
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
     * account — the consignment path used the first order's key for all of them (FR-000007).
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

        $multiColloConsignments = []; // parent shipment id => built shipment + collo count
        /**
         * @var Order\Shipment $shipment
         * @var Track          $magentoTrack
         */
        foreach ($shipments as $shipment) {
            $magentoTracks = $this->getTrackByShipment($shipment)->getItems();

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
     * @param array<int,array{built:BuiltShipment,tracks:Track[],colli:int}> $multiColloConsignments
     */
    protected function addGroupedShipments(array $multiColloConsignments): self
    {
        foreach ($multiColloConsignments as $group) {
            /** @var BuiltShipment $built */
            $built    = $group['built'];
            $quantity = (int) $group['colli'];

            if (1 < $quantity && $this->canUseMultiCollo($built->shipment(), $built->apiKey())) {
                // splitShipment() clones, divides the weight and fills secondary_shipments; it takes
                // no API key and throws on a quantity of 1, which the guard above already excludes.
                $this->builtShipments[] = $built->withShipment(
                    (new MultiColloShipmentService())->splitShipment($built->shipment(), $quantity)
                );
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

    private function returnLabelDescription(BuiltShipment $built): string
    {
        $parentDescription = (string) $built->shipment()->getOptions()->getLabelDescription();

        return sprintf(
            'Return: %s This label is valid until: %s',
            $parentDescription,
            date('d-m-Y', strtotime('+ 28 days'))
        );
    }

    /**
     * @return self
     * @throws Exception
     */
    public function updateMagentoTrack(): self
    {
        // The shipment id is already on each track — the export service wrote it per chunk — so this
        // only refreshes status and barcode. Nothing is paired by position any more.
        $latest = $this->exportService->fetchLatest($this->getMyparcelConsignmentIdsByApiKey());

        foreach ($this->getShipmentsCollection() as $shipment) {
            foreach ($this->getTrackByShipment($shipment)->getItems() as $magentoTrack) {
                $shipmentId = (int) $magentoTrack->getData('myparcel_consignment_id');
                $myParcelShipment = $latest[$shipmentId] ?? null;

                if (null === $myParcelShipment) {
                    continue;
                }

                if ($myParcelShipment->getStatus()) {
                    $magentoTrack->setData('myparcel_status', $myParcelShipment->getStatus());
                }

                if ($myParcelShipment->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelShipment->getBarcode());
                }

                $magentoTrack->save();
            }
        }

        return $this->updateOrderGrid();
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
     * @return self
     */
    protected function updateOrderGrid(): self
    {
        $shipments = $this->getShipmentsCollection();

        foreach ($shipments as $shipment) {
            if (! $shipment || ! method_exists($shipment, 'getOrder')) {
                continue;
            }

            $order = $shipment->getOrder();
            $aHtml = $this->getHtmlForGridColumns($order->getId());

            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
            $order->save();
        }

        return $this;
    }

    abstract protected function getShipmentsCollection(): ShipmentCollection;

    /**
     * @param $orderId
     *
     * @return array
     */
    private function getTracksCollectionByOrderId($orderId): array
    {
        /** @var ResourceConnection $connection */
        $connection = $this->objectManager->create(ResourceConnection::class);
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_track')]
                           )
                           ->where('main_table.order_id=?', $orderId)
        ;
        return $conn->fetchAll($select);
    }

    /**
     * @return array<string,int[]> MyParcel shipment ids grouped by resolved API key
     */
    protected function getMyparcelConsignmentIdsByApiKey(): array
    {
        return $this->objectManager->get(ShipmentApiProvider::class)
                                   ->consignmentIdsByApiKey($this->getShipmentsCollection());
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

        $v2PackageType = PackageType::toV2Name($packageType);

        if (null === $v2PackageType) {
            return false;
        }

        $capabilities = $this->getCapabilitiesRepository()->forApiKey(
            $apiKey,
            CapabilitiesRequest::forCountry($country)->withPackageType($v2PackageType)
        );

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

    private function getCapabilitiesRepository(): CapabilitiesRepository
    {
        return $this->objectManager->get(CapabilitiesRepository::class);
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
