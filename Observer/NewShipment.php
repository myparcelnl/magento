<?php
/**
 * Set MyParcel options to new track
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment;
use MyParcelBE\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;

class NewShipment implements ObserverInterface
{
    const DEFAULT_LABEL_AMOUNT = 1;
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
     * @var \MyParcelBE\Magento\Helper\Data
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
        $this->helper = $this->objectManager->get('MyParcelBE\Magento\Helper\Data');
        $this->modelTrack = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
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

        // The reason that $amount is hard coded is because this is part of multicollo, this is not possible in the Belguim plugin. However, a preparation has been made for this.
        $amount  = 1;
        /** @var \MyParcelBE\Magento\Model\Sales\TrackTraceHolder[] $trackTraceHolders */
        $trackTraceHolders = [];
        $i                 = 1;

        while ($i <= $amount) {

            // Set MyParcel options
            $trackTraceHolder = (new TrackTraceHolder($this->objectManager, $this->helper, $shipment->getOrder()))
                ->createTrackTraceFromShipment($shipment);
            $trackTraceHolder->convertDataFromMagentoToApi($trackTraceHolder->mageTrack, $options);

            $trackTraceHolders[] = $trackTraceHolder;

            $i ++;
        }

        // All multicollo holders are the same, so use the first for the SDK
        $firstTrackTraceHolder = $trackTraceHolders[0];

        $this->orderCollection->myParcelCollection
            ->addMultiCollo($firstTrackTraceHolder->consignment, $amount ?? self::DEFAULT_LABEL_AMOUNT)
            ->createConcepts()
            ->setLatestData();

        foreach ($this->orderCollection->myParcelCollection as $consignment) {
            $trackTraceHolder = array_pop($trackTraceHolders);
            $trackTraceHolder->mageTrack
                ->setData('myparcel_consignment_id', $consignment->getConsignmentId())
                ->setData('myparcel_status', 1);
            $shipment->addTrack($trackTraceHolder->mageTrack);
        }

        $this->updateTrackGrid($shipment);
    }

    /**
     * Update sales_order
     *
     * Magento puts our two columns sales_order automatically to sales_order_grid
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     *
     * @throws \Exception
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
