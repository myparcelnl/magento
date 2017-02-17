<?php
/**
 * Set MyParcel options to new track
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

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MyParcelTrackTrace;

class NewShipment implements ObserverInterface
{
    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Magento\Sales\Model\Order\Shipment\Track
     */
    private $modelTrack;

    /**
     * @var MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * @var \MyParcelNL\Magento\Helper\Data
     */
    private $helper;

    /**
     * NewShipment constructor.
     */
    public function __construct()
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->orderCollection = new MagentoOrderCollection($this->objectManager);
        $this->request = $this->objectManager->get('Magento\Framework\App\RequestInterface');
        $this->helper = $this->objectManager->get('MyParcelNL\Magento\Helper\Data');
        $this->modelTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
    }

    /**
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        $shipment = $observer->getEvent()->getShipment();
        var_dump('new shipment id: ' . $shipment->getId());
        $this->setMagentoAndMyParcelTrack($shipment);
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack(Shipment $shipment)
    {
        $options = $this->request->getParam('mypa', []);

        // Set MyParcel options
        $myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper))
            ->createTrackTraceFromShipment($shipment)
            ->convertDataFromMagentoToApi()
            ->setPackageType((int)isset($options['package_type']) ? (int)$options['package_type'] : 1)
            ->setOnlyRecipient((bool)isset($options['only_recipient']))
            ->setSignature((bool)isset($options['signature']))
            ->setReturn((bool)isset($options['return']))
            ->setLargeFormat((bool)isset($options['large_format']))
            ->setInsurance((int)isset($options['insurance']) ? $options['insurance'] : false);

        // Do the request
        $this->orderCollection->myParcelCollection
            ->addConsignment($myParcelTrack)
            ->createConcepts()
            ->setLatestData();

        $consignmentId = $this
            ->orderCollection
            ->myParcelCollection
            ->getConsignmentByReferenceId($myParcelTrack->mageTrack->getId())
            ->getMyParcelConsignmentId();

        $myParcelTrack->mageTrack->setData(
            'myparcel_consignment_id',
            $consignmentId
        )->save();

        /*
        * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
        */
        $this->addOrdersToCollection([$shipment->getOrderId()]);

        $this->orderCollection->updateMagentoTrack();
    }

    /**
     * @param $orderIds int[]
     */
    private function addOrdersToCollection($orderIds)
    {
        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Collection $collection
         */
        $collection = $this->objectManager->get(MagentoOrderCollection::PATH_MODEL_ORDER);
        $collection->addAttributeToFilter('entity_id', ['in' => $orderIds]);
        $this->orderCollection->setOrderCollection($collection);
    }
}
