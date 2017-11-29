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
        $this->request = $this->objectManager->get('Magento\Framework\App\RequestInterface');
        $this->orderCollection = new MagentoOrderCollection($this->objectManager, $this->request);
        $this->helper = $this->objectManager->get('MyParcelNL\Magento\Helper\Data');
        $this->modelTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
    }

    /**
     * Create MyParcel concept
     *
     * @param Observer $observer
     *
     * @return $this
     */
    public function execute(Observer $observer)
    {
        if ($this->request->getParam('mypa_create_from_observer')) {
            $this->request->setParams(['myparcel_track_email' => true]);
            $shipment = $observer->getEvent()->getShipment();
            $this->setMagentoAndMyParcelTrack($shipment);
        }
    }

    /**
     * Set MyParcel Tracks and update order grid
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack(Shipment $shipment)
    {
        $options = $this->orderCollection->setOptionsFromParameters()->getOptions();

        // Set MyParcel options
        $myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper, $shipment->getOrder()))
            ->createTrackTraceFromShipment($shipment);
        $myParcelTrack->convertDataFromMagentoToApi($myParcelTrack->mageTrack, $options);

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

        $myParcelTrack->mageTrack
            ->setData('myparcel_consignment_id', $consignmentId)
            ->setData('myparcel_status', 1);
        $shipment->addTrack($myParcelTrack->mageTrack);

        $this->updateTrackGrid($shipment);
    }

    /**
     * Update sales_order
     *
     * Magento puts our two columns sales_order automatically to sales_order_grid
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     */
    private function updateTrackGrid($shipment)
    {
        $aHtml = $this->orderCollection->getHtmlForGridColumns($shipment->getOrder()->getId());
        $shipment->getOrder()
            ->setData('track_status', $aHtml['track_status'])
            ->setData('track_number', $aHtml['track_number'])
        ->save();
    }
}
