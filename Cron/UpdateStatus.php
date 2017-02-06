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
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\magento\Cron;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MyParcelTrackTrace;

class UpdateStatus
{
    const PATH_MODEL_ORDER_TRACK = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';
    const PATH_MODEL_ORDER = '\Magento\Sales\Model\ResourceModel\Order\Collection';

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
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, $areaList);
        $this->helper = $this->objectManager->create(MagentoOrderCollection::PATH_HELPER_DATA);
    }

    /**
     * Run the cron job
     * @return $this
     */
    public function execute()
    {
        $this->setOrdersToUpdate();
        $this->orderCollection->myParcelCollection->setLatestData();
        $this->orderCollection->updateMagentoTrack();

        return $this;
    }

    /**
     * Get all order to update the data
     * @throws \Magento\Framework\Exception\LocalizedException
     * @todo; Filter max date in the past
     */
    private function setOrdersToUpdate()
    {
        /**
         * @var                                                                    $magentoTrack Order\Shipment\Track
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $trackCollection
         */
        $trackCollection = $this->objectManager->get(self::PATH_MODEL_ORDER_TRACK);
        $trackCollection->addAttributeToFilter('myparcel_status', [0, 1, 2, 3, 4, 5, 6,]);
        foreach ($trackCollection as $magentoTrack) {
            if ($magentoTrack->getCarrierCode() == MyParcelTrackTrace::POSTNL_CARRIER_CODE &&
                $magentoTrack->getData('myparcel_consignment_id')
            ) {
                $myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper))
                    ->setApiKey($this->helper->getGeneralConfig('api/key'))
                    ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
                    ->setReferenceId($magentoTrack->getEntityId());
                $this
                    ->orderCollection->addOrder($magentoTrack->getShipment()->getOrder())
                    ->addMyParcelConsignment($myParcelTrack);
            }
        }

        return $this;
    }
}
