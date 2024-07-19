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

namespace MyParcelBE\Magento\Model\Sales;

use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Model\Order\Email\Sender\TrackSender;
use MyParcelBE\Magento\Model\Source\ReturnInTheBox;
use MyParcelBE\Magento\Model\Source\SourceItem;
use MyParcelBE\Magento\Observer\NewShipment;
use MyParcelBE\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Consignment\BaseConsignment;
use Magento\Framework\App\ResourceConnection;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelBE\Magento\Model\Sales
 */
abstract class MagentoCollection implements MagentoCollectionInterface
{
    public const PATH_HELPER_DATA                    = '\MyParcelBE\Magento\Helper\Data';
    public const PATH_MODEL_ORDER                    = '\Magento\Sales\Model\ResourceModel\Order\Collection';
    public const PATH_MODEL_SHIPMENT                 = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Collection';
    public const ERROR_ORDER_HAS_NO_SHIPMENT         = 'No shipment can be made with this order. Shipments can not be created if the status is On Hold or if the product is digital.';
    public const ERROR_ORDER_HAS_NO_SOURCE           = 'Creating shipments via bulk actions is not possible for orders without a source. Go to the details of the order and process the shipment manually.';
    public const DEFAULT_ERROR_ORDER_HAS_NO_SOURCE   = 'Source item not found by source code';

    private const PATH_ORDER_TRACK            = '\Magento\Sales\Model\Order\Shipment\Track';
    private const PATH_MANAGER_INTERFACE      = '\Magento\Framework\Message\ManagerInterface';
    private const PATH_ORDER_TRACK_COLLECTION = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';

    /**
     * @var MyParcelCollection
     */
    public $myParcelCollection;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    protected $moduleManager;

    /**
     * @var \MyParcelBE\Magento\Model\Source\SourceItem
     */
    protected $sourceItem;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    public $request = null;

    /**
     * @var TrackSender
     */
    protected $trackSender;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var Order\Shipment\Track
     */
    protected $modelTrack;

    /**
     * @var \Magento\Framework\App\AreaList
     */
    protected $areaList;

    /**
     * @var \Magento\Framework\Message\ManagerInterface $messageManager
     */
    protected $messageManager;

    /**
     * @var \MyParcelBE\Magento\Helper\Data
     */
    protected $helper;

    /**
     * @var array
     */
    protected $options = [
        'create_track_if_one_already_exist' => true,
        'request_type'                      => 'download',
        'package_type'                      => 'default',
        'carrier'                           => 'postnl',
        'positions'                         => null,
        'signature'                         => null,
        'only_recipient'                    => null,
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
    ) {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager      = $objectManager;
        $this->moduleManager      = $objectManager->get(Manager::class);
        $this->request            = $request;
        $this->trackSender        = $this->objectManager->get(
            'MyParcelBE\Magento\Model\Order\Email\Sender\TrackSender'
        );
        $this->helper             = $objectManager->create(self::PATH_HELPER_DATA);
        $this->modelTrack         = $objectManager->create(self::PATH_ORDER_TRACK);
        $this->messageManager     = $objectManager->create(self::PATH_MANAGER_INTERFACE);
        $this->myParcelCollection = (new MyParcelCollection())->setUserAgents(
            ['Magento2' => $this->helper->getVersion()]
        );

        $this->setSourceItemWhenInventoryApiEnabled();
    }

    /**
     * Set options from POST or GET variables
     *
     * @return $this
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

        // Remove position if paper size == A6
        if ($this->request->getParam('mypa_paper_size', 'A6') !== 'A4') {
            $this->options['positions'] = null;
        }

        if ($this->request->getParam('mypa_request_type') === null) {
            $this->options['request_type'] = 'download';
        }

        if ($this->request->getParam('mypa_request_type') !== 'concept') {
            $this->options['create_track_if_one_already_exist'] = false;
        }

        $returnInTheBox = $this->helper->getGeneralConfig('print/return_in_the_box');
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
     * Add MyParcel consignment to collection
     *
     * @param $consignment BaseConsignment
     *
     * @return $this
     * @throws \Exception
     */
    public function addConsignment(BaseConsignment $consignment)
    {
        $this->myParcelCollection->addConsignment($consignment);

        return $this;
    }

    /**
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->helper->getApiKey();
    }

    /**
     * @return string|null
     */
    public function getExportMode(): ?string
    {
        return $this->helper->getExportMode();
    }

    /**
     * @return bool
     */
    public function apiKeyIsCorrect(): bool
    {
        return $this->helper->apiKeyIsCorrect();
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
            $areaObject = $this->areaList->getArea(\Magento\Framework\App\Area::AREA_ADMINHTML);
            $areaObject->load(\Magento\Framework\App\Area::PART_TRANSLATE);
        }

        return $this->getHtmlForGridColumnsByTracks($this->getTracksCollectionByOrderId($orderId));
    }

    /**
     * @param Order\Shipment\Track[]|\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $tracks
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
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
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
     * @return \Magento\Sales\Model\Order\Shipment\Track
     * @throws \Exception
     */
    protected function setNewMagentoTrack($shipment)
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Track $track */
        $track = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(TrackTraceHolder::MYPARCEL_CARRIER_CODE)
            ->setTitle(TrackTraceHolder::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber(TrackAndTrace::VALUE_EMPTY)
            ->save();

        return $track;
    }

    /**
     * Get all tracks
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment $shipment
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection
     */
    protected function getTrackByShipment($shipment)
    {
        /* @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $collection */
        $collection = $this->objectManager->create(self::PATH_ORDER_TRACK_COLLECTION);
        $collection
            ->addAttributeToFilter('parent_id', $shipment->getId());

        return $collection;
    }

    /**
     * Get MyParcel Track from Magento Track
     *
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return TrackTraceHolder $myParcelTrack
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function createConsignmentAndGetTrackTraceHolder($magentoTrack): TrackTraceHolder
    {
        $trackTraceHolder = new TrackTraceHolder(
            $this->objectManager,
            $this->helper,
            $magentoTrack->getShipment()->getOrder()
        );
        $trackTraceHolder->convertDataFromMagentoToApi($magentoTrack, $this->options);

        return $trackTraceHolder;
    }

    /**
     * @return $this
     */
    public function syncMagentoToMyparcel(): self
    {
        $consignmentIds = $this->getMyparcelConsignmentIdsForShipments();

        try {
            $this->myParcelCollection->addConsignmentByConsignmentIds(
                $consignmentIds,
                $this->getApiKey()
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this;
    }

    /**
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    public function createMyParcelConcepts(): self
    {
        if (! count($this->myParcelCollection)) {
            $this->messageManager->addWarningMessage(__('myparcelbe_magento_error_no_shipments_to_process'));
            return $this;
        }

        try {
            $this->myParcelCollection->createConcepts();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this;
        }

        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * Add MyParcel Track from Magento Track
     *
     * @return $this
     * @throws \Exception
     */
    public function setNewMyParcelTracks(): self
    {
        $shipments = $this->getShipmentsCollection();

        $multiColloConsignments = [];
        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($shipments as $shipment) {
            $magentoTracks = $this->getTrackByShipment($shipment)->getItems();

            foreach ($magentoTracks as $magentoTrack) {
                if ($magentoTrack->getData('myparcel_consignment_id')
                    || TrackTraceHolder::MYPARCEL_CARRIER_CODE !== $magentoTrack->getCarrierCode()) {
                    continue;
                }

                $parentId = $magentoTrack->getData('parent_id');

                if (isset($multiColloConsignments[$parentId])) {
                    $multiColloConsignments[$parentId]['colli']++;
                    continue;
                }

                $consignment = $this->createConsignmentAndGetTrackTraceHolder($magentoTrack)->consignment;

                $multiColloConsignments[$parentId] = [
                    'consignment' => $consignment,
                    'colli'       => 1,
                ];
            }
        }

        return $this->addGroupedConsignments($multiColloConsignments);
    }

    /**
     * @param array $multiColloConsignments
     *
     * @return $this
     */
    protected function addGroupedConsignments(array $multiColloConsignments): self
    {
        foreach ($multiColloConsignments as $multiColloConsignment) {
            $consignment = $multiColloConsignment['consignment'];
            $quantity    = $multiColloConsignment['colli'];

            if (1 < $quantity && $this->canUseMultiCollo($consignment)) {
                $this->myParcelCollection->addMultiCollo($consignment, $quantity);
                continue;
            }

            $this->addConsignmentMultipleTimes($consignment, $quantity);
        }

        return $this;
    }

    /**
     * @param \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment $consignment
     * @param int                                                       $quantity
     *
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    protected function addConsignmentMultipleTimes(AbstractConsignment $consignment, int $quantity): void
    {
        $i = 0;

        while ($i < $quantity) {
            try {
                $this->myParcelCollection->addConsignment($consignment);
            } catch (\Exception $e) {
                return;
            }

            ++$i;
        }
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function addReturnInTheBox(string $returnOptions): void
    {
        $this->myParcelCollection
            ->generateReturnConsignments(
                false,
                function (
                    AbstractConsignment $returnConsignment,
                    AbstractConsignment $parent
                ) use ($returnOptions): AbstractConsignment {
                    $returnConsignment->setLabelDescription(
                        'Return: ' . $parent->getLabelDescription() .
                        ' This label is valid until: ' . date("d-m-Y", strtotime("+ 28 days"))
                    );
                    $returnConsignment->setReferenceIdentifier($parent->getReferenceIdentifier());

                    if (ReturnInTheBox::NO_OPTIONS === $returnOptions) {
                        $returnConsignment->setOnlyRecipient(false);
                        $returnConsignment->setSignature(false);
                        $returnConsignment->setAgeCheck(false);
                        $returnConsignment->setReturn(false);
                        $returnConsignment->setLargeFormat(false);
                        $returnConsignment->setInsurance(0);
                    }

                    return $returnConsignment;
                }
            );
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function updateMagentoTrack(): self
    {
        $shipments = $this->getShipmentsCollection();

        foreach ($shipments as $shipment) {
            $consignments    = $this->myParcelCollection->getConsignmentsByReferenceId($shipment->getEntityId());
            $trackCollection = $this->getTrackByShipment($shipment)->getItems();

            foreach ($trackCollection as $magentoTrack) {
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId(
                    $magentoTrack->getData('myparcel_consignment_id')
                );

                if (! $myParcelTrack) {
                    if ($consignments->isEmpty()) {
                        continue;
                    }
                    $myParcelTrack = $consignments->pop();

                    if (! $myParcelTrack->getConsignmentId()) {
                        continue;
                    }
                    $magentoTrack->setData('myparcel_consignment_id', $myParcelTrack->getConsignmentId());
                }

                if ($myParcelTrack->status) {
                    $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());
                }

                if ($myParcelTrack->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                }

                $magentoTrack->save();
            }
        }

        return $this->updateOrderGrid();
    }

    /**
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function addReturnShipments(): self
    {
        $returnInTheBoxOptions = $this->options['return_in_the_box'] ?? null;

        if ($returnInTheBoxOptions && $this->myParcelCollection->isNotEmpty()) {
            $this->addReturnInTheBox($returnInTheBoxOptions);
        }

        return $this;
    }

    /**
     * @return $this
     */
    protected function updateOrderGrid(): self
    {
        $shipments = $this->getShipmentsCollection();

        if (! $shipments) {
            return $this;
        }

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

    abstract protected function getShipmentsCollection(): \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection;

    /**
     * @param $orderId
     *
     * @return array
     */
    private function getTracksCollectionByOrderId($orderId): array
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create(ResourceConnection::class);
        $conn       = $connection->getConnection();
        $select     = $conn->select()
                           ->from(
                               ['main_table' => $connection->getTableName('sales_shipment_track')]
                           )
                           ->where('main_table.order_id=?', $orderId);
        return $conn->fetchAll($select);
    }

    /**
     * @return array
     */
    protected function getMyparcelConsignmentIdsForShipments(): array
    {
        $shipments = $this->getShipmentsCollection();
        $consignmentIds = [];

        foreach ($shipments as $shipment) {
            $trackCollection = $shipment->getAllTracks();
            foreach ($trackCollection as $magentoTrack) {
                $consignmentId = (int) $magentoTrack->getData('myparcel_consignment_id');
                if ($consignmentId) {
                    $consignmentIds[] = $consignmentId;
                }
            }
        }
        return $consignmentIds;
    }

    /**
     * @param \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment $consignment
     *
     * @return bool whether $consignment properties allow for multicollo shipments
     */
    public function canUseMultiCollo(AbstractConsignment $consignment): bool
    {
        $carrier = $consignment->getCarrierId();
        $country = $consignment->getCountry();
        $package = $consignment->getPackageType();

        return ($consignment::CC_NL === $country || $consignment::CC_BE === $country)
            && CarrierPostNL::ID === $carrier
            && $consignment::PACKAGE_TYPE_PACKAGE === $package;
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
