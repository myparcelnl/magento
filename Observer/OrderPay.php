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

use Magento\Checkout\Controller\Action;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\order\shipment\Collection;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;

class OrderPay implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;

    protected $orderFactory;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var Track
     */
    private $modelTrack;

    /**
     * @var MagentoOrderCollection
     */
    private $orderCollection;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var MagentoShipmentCollection
     */
    private $shipmentCollection;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * NewShipment constructor.
     *
     * @param \MyParcelNL\Magento\Model\Sales\MagentoOrderCollection|null $orderCollection
     */
    public function __construct(MagentoOrderCollection $orderCollection = null)
    {
        $this->objectManager   = ObjectManager::getInstance();
        $this->request         = $this->objectManager->get('Magento\Framework\App\RequestInterface');
        $this->orderCollection = $orderCollection ?? new MagentoOrderCollection($this->objectManager, $this->request);
        $this->helper          = $this->objectManager->get('MyParcelNL\Magento\Helper\Data');
        $this->modelTrack      = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');

        $this->orderFactory =$this->objectManager->get('\Magento\Sales\Model\Order');
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return void
     * @throws \Exception
     */
    public function execute(Observer $observer)
    {
//        $orderIds = $observer->getEvent()->getOrderIds();
//        $lastorderId = $orderIds[0];

//        $shipment = $this->orderFactory->load($lastorderId);

//        $shipment = $observer->getEvent()->getShipment();
        $shipmentId = $this->getRequest()->getParam('selected');
        $this->setMagentoAndMyParcelTrack($shipmentId);
    }

    /**
     * Set MyParcel Tracks and update order grid
     *
     * @param $shipmentIds
     *
     * @return OrderPay
     * @throws LocalizedException
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack($shipmentIds)
    {
        $this->addShipmentsToCollection($shipmentIds);

        $this->shipmentCollection
            ->setOptionsFromParameters()
            ->setMagentoTrack()
            ->setMyParcelTrack()
            ->createMyParcelConcepts()
            ->updateGridByShipment();

        if ($this->shipmentCollection->getOption('request_type') == 'concept') {
            return $this;
        }

        $this->shipmentCollection
            ->setPdfOfLabels()
            ->updateMagentoTrack()
            ->sendTrackEmailFromShipments()
            ->downloadPdfOfLabels();

        return $this;
    }

    /**
     * @param $shipmentIds int[]
     */
    private function addShipmentsToCollection($shipmentIds)
    {
        //Get Object Manager Instance
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        /**
         * @var Collection $collection
         */
        $collection = $objectManager->get(MagentoShipmentCollection::PATH_MODEL_SHIPMENT);
        $collection->addAttributeToFilter('entity_id', ['in' => $shipmentIds]);
        $this->shipmentCollection->setShipmentCollection($collection);
    }
}
