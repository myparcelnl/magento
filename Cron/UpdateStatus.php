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
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Cron;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Api\Data\ShipmentTrackInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MyParcelTrackTrace;

class UpdateStatus
{
    const PATH_MODEL_ORDER_TRACK = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    private $helper;

    /**
     * UpdateStatus constructor.
     *
     * @param \Magento\Framework\App\AreaList $areaList
     *
     * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
     */
    public function __construct(\Magento\Framework\App\AreaList $areaList)
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, null, $areaList);
        $this->helper = $this->objectManager->create(MagentoOrderCollection::PATH_HELPER_DATA);
    }

    /**
     * Run the cron job
     *
     * @return $this
     */
    public function execute()
    {
        $this->setOrdersToUpdate();
        $this->orderCollection
            ->setMyParcelTrack()
            ->setLatestData()
            ->updateMagentoTrack();

        return $this;
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
            ->addAttributeToFilter('myparcel_status', [0, 1, 2, 3, 4, 5, 6])
            ->addAttributeToFilter('myparcel_consignment_id', ['notnull' => true])
            ->addAttributeToFilter(ShipmentTrackInterface::CARRIER_CODE, MyParcelTrackTrace::MYPARCEL_CARRIER_CODE)
            ->setPageSize(300)
            ->setOrder('order_id', 'DESC');

        return array_unique(array_column($trackCollection->getData(), 'order_id'));
    }

    /**
     * Get collection from order ids
     *
     * @param $orderIds int[]
     */
    private function addOrdersToCollection($orderIds)
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
