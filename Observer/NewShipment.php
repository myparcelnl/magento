<?php
/**
 * Set MyParcel options to new track
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
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
     * @var MyParcelCollection
     */
    private $api;

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
        $this->api = new MyParcelCollection();
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
        $this->setMagentoAndMyParcelTrack($shipment);
    }

    /**
     * @param $shipment
     *
     * @throws \Exception
     */
    private function setMagentoAndMyParcelTrack($shipment)
    {
        $options = $this->request->getParam('mypa', []);

        $postNLTrack = new MyParcelTrackTrace($this->objectManager, $this->helper);
        $postNLTrack->createTrackTraceFromShipment($shipment);
        $postNLTrack->convertDataFromMagentoToApi();
        $postNLTrack
            ->setPackageType((int)isset($options['package_type']) ? (int)$options['package_type'] : 1)
            ->setOnlyRecipient((bool)isset($options['only_recipient']))
            ->setSignature((bool)isset($options['signature']))
            ->setReturn((bool)isset($options['return']))
            ->setLargeFormat((bool)isset($options['large_format']))
            ->setInsurance((int)isset($options['insurance']) ? $options['insurance'] : false);
        $this->api->addConsignment($postNLTrack);
        $this->api->createConcepts();
        $this->api->setLatestData();
        $this->updateMagentoTrack();
    }

    /**
     * Update all the tracks that made created via the API
     */
    private function updateMagentoTrack()
    {
        /** @var $magentoTrack \Magento\Sales\Model\Order\Shipment\Track */
        foreach ($this->api->getConsignments() as $postNLTrack) {
            $magentoTrack = $this->modelTrack->load($postNLTrack->getReferenceId());
            $magentoTrack->setData('myparcel_consignment_id', $postNLTrack->getMyParcelConsignmentId());
            $magentoTrack->setData('api_status', $postNLTrack->getStatus());
            $magentoTrack->save();
        }

        $this->updateOrderGrid($magentoTrack);
    }

    /**
     * Update column track_status in sales_order_grid
     *
     * @param $magentoTrack \Magento\Sales\Model\Order\Shipment\Track
     */
    private function updateOrderGrid($magentoTrack)
    {
        $order = $magentoTrack->getShipment()->getOrder();
        $aHtml = $this->getHtmlForGridColumns($order);
        $order->setData('track_status', $aHtml['track_status']);
        $order->setData('track_number', $aHtml['track_number']);
        $order->save();
    }

    /**
     * @param $order Order
     *
     * @return array
     */
    private function getHtmlForGridColumns($order)
    {
        $data = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /** @var $shipment \Magento\Sales\Model\Order\Shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {
            // Set all track data in array
            foreach ($shipment->getTracks() as $track) {
                $data['track_status'][] = __('status_' . $track->getData('api_status'));
                $data['track_number'][] = $track->getData('track_number');
            }
        }

        // Create html
        $columnHtml['track_status'] = implode(' \r\n ', $data['track_status']);
        $columnHtml['track_number'] = implode(' \r\n ', $data['track_number']);

        return $columnHtml;
    }
}
