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

namespace MyParcelNL\Magento\Cron;

use DateTime;
use Exception;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection;
use MyParcelNL\Magento\Api\ShipmentStatus;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;

class UpdateStatus
{
    public const ORDER_ID_NOT_TO_PROCESS = '000000000';
    public const ORDER_STATUS_EXPORTED = 'Exported';
    public const PATH_MODEL_ORDER_TRACK = Collection::class;


    private ObjectManager $objectManager;
    private \Magento\Sales\Model\ResourceModel\Order $orderResource;
    private MagentoOrderCollection $orderCollection;
    private Config                 $config;

    /**
     * UpdateStatus constructor.
     *
     * @param AreaList $areaList
     * @param \Magento\Sales\Model\ResourceModel\Order $orderResource
     *
     * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
     */
    public function __construct(
        AreaList                                 $areaList,
        \Magento\Sales\Model\ResourceModel\Order $orderResource
    )
    {
        $this->objectManager   = $objectManager = ObjectManager::getInstance();
        $this->config          = $objectManager->get(Config::class);
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, null, $areaList);
        $this->orderResource   = $orderResource;
    }

    /**
     * Run the cron job
     *
     * @return $this
     * @throws LocalizedException
     * @throws Exception
     */
    public function execute(): self
    {
        if (Config::EXPORT_MODE_PPS === $this->config->getExportMode()) {
            return $this->updateStatusPPS();
        }
        return $this->updateStatusShipments();
    }

    /**
     * Handles orders exported using Orderbeheer (PPS) setting.
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
     * @throws AlreadyExistsException
     * @throws AccountNotActiveException
     * @throws ApiException
     * @throws MissingFieldException
     * @throws Exception
     */
    private function updateStatusPPS(): self
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $magentoOrders
         */
        $magentoOrders = $this->objectManager->get(MagentoCollection::PATH_MODEL_ORDER_COLLECTION);
        $magentoOrders
            ->addFieldToSelect('increment_id')
            ->addFieldToSelect('entity_id')
            ->addAttributeToFilter('track_number', ['null' => true])
            ->addAttributeToFilter('track_status', self::ORDER_STATUS_EXPORTED)
            ->setPageSize(300)
            ->setOrder('increment_id', 'DESC');

        $orderIdsToCheck = array_unique(array_column($magentoOrders->getData(), 'increment_id'));
        $apiOrders       = OrderCollection::query($this->config->getGeneralConfig('api/key'));
        $orderIdsDone    = [];

        foreach ($apiOrders->getIterator() as $apiOrder) {
            $incrementId = $apiOrder->getExternalIdentifier();
            $shipment    = $apiOrder->getOrderShipments()[0]['shipment'] ?? null;

            if (!$incrementId
                || !$shipment
                || isset($orderIdsDone[$incrementId])
                || !array_contains($orderIdsToCheck, $incrementId)) {
                continue;
            }

            $orderIdsDone[$incrementId] = $incrementId;
            $barcode                    = $shipment['external_identifier'] ?? TrackAndTrace::VALUE_PRINTED;

            if (!$this->apiShipmentIsShipped($shipment)) {
                continue;
            }

            $magentoOrder = $this->objectManager->create('Magento\Sales\Model\Order')
                                                ->loadByIncrementId($incrementId);

            if (!$magentoOrder->canShip()) {
                $orderIdsDone[$incrementId] = self::ORDER_ID_NOT_TO_PROCESS;

                Logger::notice('Order is shipped from backoffice but Magento will not create a shipment.');
                $this->setShippedWithoutShipment($magentoOrder, $barcode);
            }
        }

        if (!$orderIdsDone) {
            Logger::notice('PPS: no orders updated');

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
            if (!in_array($arrayWithIds['increment_id'], $orderIncrementIds)) {
                continue;
            }
            $orderEntityIds[] = $arrayWithIds['entity_id'];
        }

        if (!$orderEntityIds) {
            return $this;
        }

        Logger::notice(sprintf('PPS: update orders %s', implode(', ', $orderIncrementIds ?? [])));
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
     * @throws LocalizedException
     * @throws Exception
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
     * @throws AlreadyExistsException
     */
    private function setShippedWithoutShipment(Order $magentoOrder, string $barcode): void
    {
        Logger::notice(
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
            && (!in_array($status, [ShipmentStatus::CREDITED, ShipmentStatus::CANCELLED], true));
    }

    /**
     * Get all order to update the data
     *
     * @throws LocalizedException
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
         * @var Order\Shipment\Track $magentoTrack
         * @var Collection           $trackCollection
         */
        $trackCollection = $this->objectManager->get(self::PATH_MODEL_ORDER_TRACK);
        $trackCollection
            ->addFieldToSelect('order_id')
            ->addAttributeToFilter('myparcel_status', [1, 2, 3, 4, 5, 6, 8])
            ->addAttributeToFilter('myparcel_consignment_id', ['notnull' => true])
            ->addAttributeToFilter(ShipmentTrackInterface::CARRIER_CODE, Carrier::CODE)
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
        $now        = new DateTime('now -14 day');
        $collection = $this->objectManager->get(MagentoCollection::PATH_MODEL_ORDER_COLLECTION);
        $collection
            ->addAttributeToFilter('entity_id', ['in' => $orderIds])
            ->addFieldToFilter('created_at', ['gteq' => $now->format('Y-m-d H:i:s')]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
