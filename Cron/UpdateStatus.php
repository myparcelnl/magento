<?php
/**
 * Update MyParcel data
 * Trigger actions:
 * - Update status in Track
 * - Update barcode in Track
 * - Update html status in order
 * - Update html barcode in order
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Cron;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Api\ShipmentStatus;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Services\Web\OrderWebService;

class UpdateStatus
{
    public const ORDER_ID_NOT_TO_PROCESS         = 0;
    public const ORDER_STATUS_EXPORTED           = 'Exported';
    public const PATH_MODEL_ORDER_TRACK          = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';
    public const PATH_MODEL_ORDER                = '\Magento\Sales\Model\ResourceModel\Order\Collection';

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order
     */
    private $orderResource;

    /**
     * @var \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * UpdateStatus constructor.
     *
     * @param \Magento\Framework\App\AreaList          $areaList
     * @param \Psr\Log\LoggerInterface                 $logger
     * @param \Magento\Sales\Model\ResourceModel\Order $orderResource
     *
     * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
     */
    public function __construct(
        \Magento\Framework\App\AreaList          $areaList,
        \Psr\Log\LoggerInterface                 $logger,
        \Magento\Sales\Model\ResourceModel\Order $orderResource
    ) {
        $this->objectManager   = ObjectManager::getInstance();
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, null, $areaList);
        $this->logger          = $logger;
        $this->orderResource   = $orderResource;
    }

    /**
     * Run the cron job
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    public function execute(): self
    {
        return $this->updateStatusOrderbeheer()->updateStatusShipments();
    }

    /**
     * Handles orders exported using Orderbeheer setting.
     * Gets (max 300) orders from Magento that are eligible, gets the most recently updated orders from the api.
     * When the api order is one of the eligible Magento orders and it is shipped, adds the shipment in Magento.
     *
     * @return $this
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    private function updateStatusOrderbeheer(): self
    {
        $magentoOrders = $this->objectManager->get(self::PATH_MODEL_ORDER);
        $magentoOrders->addFieldToSelect('entity_id')
            ->addAttributeToFilter('track_number', ['null' => true])
            ->addAttributeToFilter('track_status', self::ORDER_STATUS_EXPORTED)
            ->setPageSize(300)
            ->setOrder('entity_id', 'DESC');

        $orderIdsToCheck = array_unique(array_column($magentoOrders->getData(), 'entity_id'));
        $apiOrders       = (new OrderWebService())->setApiKey($this->orderCollection->getApiKey())->getOrders();
        $orderIdsDone    = [];

        foreach ($apiOrders as $apiOrder) {
            $incrementId = (int) ($apiOrder['external_identifier'] ?? self::ORDER_ID_NOT_TO_PROCESS);
            $shipments   = $apiOrder['order_shipments'] ?? [];

            if (! $incrementId
                || ! $shipments
                || isset($orderIdsDone[$incrementId])
                || ! array_contains($orderIdsToCheck, (string) $incrementId)) {
                continue;
            }

            $orderIdsDone[$incrementId] = $incrementId;
            $shipment                   = $shipments[0]['shipment'];
            $barcode                    = $shipments[0]['external_shipment_identifier'] ?? TrackAndTrace::VALUE_PRINTED;

            if (! $this->apiShipmentIsShipped($shipment)) {
                continue;
            }

            $magentoOrder = $this->objectManager->create('Magento\Sales\Model\Order')
                ->loadByIncrementId($incrementId);

            if (! $magentoOrder->canShip()) {
                $orderIdsDone[$incrementId] = self::ORDER_ID_NOT_TO_PROCESS;

                $this->setShippedWithoutShipment($magentoOrder, $barcode);
            }
        }

        if (! $orderIdsDone) {
            $this->logger->notice('Orderbeheer: no orders updated');

            return $this;
        }

        $this->addOrdersToCollection($orderIdsDone);
        $this->orderCollection->setNewMagentoShipment(false)
            ->setMagentoTrack()
            ->setNewMyParcelTracks()
            ->setLatestData()
            ->updateMagentoTrack();

        return $this;
    }

    /**
     * Handles orders that have regular shipments, first removes any lingering orders in $this->orderCollection
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Exception
     */
    private function updateStatusShipments(): self {
        $this->setOrdersToUpdate();
        $this->orderCollection
            ->syncMagentoToMyparcel()
            ->setNewMyParcelTracks()
            ->setLatestData()
            ->updateMagentoTrack();

        return $this;
    }

    /**
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    private function setShippedWithoutShipment(Order $magentoOrder, string $barcode): void
    {
        $this->logger->notice(
            sprintf(
                'Order %s set to shipped without shipment',
                $magentoOrder->getIncrementId()
            )
        );

        $magentoOrder->setData('track_number', json_encode([$barcode]));
        $this->orderResource->save($magentoOrder);
    }

    /**
     * @param array $shipment
     *
     * @return bool
     */
    private function apiShipmentIsShipped(array $shipment): bool
    {
        $status = $shipment['status'] ?? null;

        return $status >= ShipmentStatus::PRINTED_MINIMUM
            && (! in_array($status, [ShipmentStatus::CREDITED, ShipmentStatus::CANCELLED]));
    }

    /**
     * Get all order to update the data
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function setOrdersToUpdate()
    {
        $this->addOrdersToCollection(
            $this->getOrderIdFromTrackToUpdate()
        );

        return $this;
    }

    /**
     * Get all ids from orders that need to be updated
     *
     * @return array
     */
    private function getOrderIdFromTrackToUpdate()
    {
        /**
         * @var                                                                    $magentoTrack Order\Shipment\Track
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $trackCollection
         */
        $trackCollection = $this->objectManager->get(self::PATH_MODEL_ORDER_TRACK);
        $trackCollection
            ->addFieldToSelect('order_id')
            ->addAttributeToFilter('myparcel_status', [1, 2, 3, 4, 5, 6, 8])
            ->addAttributeToFilter('myparcel_consignment_id', ['notnull' => true])
            ->addAttributeToFilter(ShipmentTrackInterface::CARRIER_CODE, TrackTraceHolder::MYPARCEL_CARRIER_CODE)
            ->setPageSize(300)
            ->setOrder('order_id', 'DESC');

        return array_unique(array_column($trackCollection->getData(), 'order_id'));
    }

    /**
     * Get collection from order ids
     *
     * @param int[] $orderIds
     */
    private function addOrdersToCollection(array $orderIds): void
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $now = new \DateTime('now -14 day');
        $collection = $this->objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER);
        $collection
            ->addAttributeToFilter('entity_id', ['in' => $orderIds])
            ->addFieldToFilter('created_at', ['gteq' => $now->format('Y-m-d H:i:s')]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
