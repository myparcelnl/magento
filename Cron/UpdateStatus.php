<?php

declare(strict_types=1);
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

namespace MyParcelBE\Magento\Cron;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Api\ShipmentStatus;
use MyParcelBE\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;
use MyParcelBE\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;

class UpdateStatus
{
    public const ORDER_ID_NOT_TO_PROCESS         = '000000000';
    public const ORDER_STATUS_EXPORTED           = 'Exported';
    public const PATH_MODEL_ORDER_TRACK          = Collection::class;
    public const PATH_MODEL_ORDER                = \Magento\Sales\Model\ResourceModel\Order\Collection::class;

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
     * @var \MyParcelBE\Magento\Model\Sales\MagentoOrderCollection
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
        if (TrackTraceHolder::EXPORT_MODE_PPS === $this->orderCollection->getExportMode()) {
            return $this->updateStatusOrderbeheer();
        }
        return $this->updateStatusShipments();
    }

    /**
     * Handles orders exported using Orderbeheer setting.
     * Gets (max 300) orders from Magento that are eligible, gets the most recently updated orders from the api.
     * When the api order is one of the eligible Magento orders and it is shipped, adds the shipment in Magento.
     *
     * NOTE: the 'reference_identifier' as returned by the api in the order object is the increment_id of the order,
     * the order_id in the track table however is the entity_id, these are not necessarily the same (number).
     *
     * If the Magento order cannot be shipped by itself, track_number will be updated in the order table.
     * If it can be shipped, the regular shipment routine will be followed, resulting in a shipment and track_number.
     * NOTE: if the creation of the shipment fails (for instance when there is no msi-source), no further work is done,
     * this means only on the following run the track_number is updated because the order cannot be shipped anymore.
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
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $magentoOrders
         */
        $magentoOrders = $this->objectManager->get(self::PATH_MODEL_ORDER);
        $magentoOrders
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->addAttributeToFilter('track_number', ['null' => true])
            ->addAttributeToFilter('track_status', self::ORDER_STATUS_EXPORTED)
            ->setPageSize(300)
            ->setOrder('increment_id', 'DESC');

        $orderIdsToCheck = array_unique(array_column($magentoOrders->getData(), 'increment_id'));
        $apiOrders       = OrderCollection::query($this->orderCollection->getApiKey());
        $orderIdsDone    = [];

        foreach ($apiOrders->getIterator() as $apiOrder) {
            $incrementId = $apiOrder->getExternalIdentifier();
            $shipment    = $apiOrder->getOrderShipments()[0]['shipment'] ?? null;

            if (! $incrementId
                || ! $shipment
                || isset($orderIdsDone[$incrementId])
                || ! array_contains($orderIdsToCheck, $incrementId)) {
                continue;
            }

            $orderIdsDone[$incrementId] = $incrementId;
            $barcode                    = $shipment['external_identifier'] ?? TrackAndTrace::VALUE_PRINTED;

            if (! $this->apiShipmentIsShipped($shipment)) {
                continue;
            }

            $magentoOrder = $this->objectManager->create('Magento\Sales\Model\Order')
                ->loadByIncrementId($incrementId);

            if (! $magentoOrder->canShip()) {
                $orderIdsDone[$incrementId] = self::ORDER_ID_NOT_TO_PROCESS;

                $this->logger->notice('Order is shipped from backoffice but Magento will not create a shipment.');
                $this->setShippedWithoutShipment($magentoOrder, $barcode);
            }
        }

        if (! $orderIdsDone) {
            $this->logger->notice('Orderbeheer: no orders updated');

            return $this;
        }

        $orderIncrementIds = array_unique(array_values($orderIdsDone));
        $index             = array_search(self::ORDER_ID_NOT_TO_PROCESS, $orderIncrementIds);
        if (false !== $index) {
            unset($orderIncrementIds[$index]);
        }
        $orderEntityIds    = [];
        $arrayWithIdsArray = $magentoOrders->getData();

        foreach ($arrayWithIdsArray as $arrayWithIds) {
            if (! in_array($arrayWithIds['increment_id'], $orderIncrementIds)) {
                continue;
            }
            $orderEntityIds[] = $arrayWithIds['entity_id'];
        }

        if (! $orderEntityIds) {
            return $this;
        }

        $this->logger->notice(sprintf('Orderbeheer: update orders %s', implode(', ', $orderIncrementIds ?? [])));
        $this->addOrdersToCollection($orderEntityIds);

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
    private function updateStatusShipments(): self
    {
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
