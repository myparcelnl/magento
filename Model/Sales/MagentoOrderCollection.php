<?php
/**
 * Short_description
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

namespace MyParcelNL\magento\Model\Sales;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Magento\Helper\Data;


/**
 * Class MagentoOrderCollection
 * @package MyParcelNL\magento\Model\Sales
 */
class MagentoOrderCollection
{
    const PATH_HELPER_DATA = 'MyParcelNL\Magento\Helper\Data';
    const PATH_ORDER_GRID = '\Magento\Sales\Model\ResourceModel\Order\Grid\Collection';
    const PATH_ORDER_TRACK = 'Magento\Sales\Model\Order\Shipment\Track';
    const URL_SHOW_POSTNL_STATUS = 'https://mijnpakket.postnl.nl/Inbox/Search';

    /**
     * @var ObjectManagerInterface
     */
    private $_objectManager;

    /**
     * @var MyParcelCollection
     */
    private $myparcel_collection;

    /**
     * @var Order[]
     */
    private $orders;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order\Shipment\Track
     */
    private $modelTrack;

    /**
     * MassTrackTraceLabel constructor.
     *
     * @param ObjectManagerInterface $objectManagerInterface
     */
    public function __construct(ObjectManagerInterface $objectManagerInterface)
    {

        $this->_objectManager = $objectManagerInterface;

        $this->helper = $objectManagerInterface->create(self::PATH_HELPER_DATA);
        $this->modelTrack = $objectManagerInterface->create(self::PATH_ORDER_TRACK);
        $this->myparcel_collection = new MyParcelCollection();
    }

    /**
     * @return MyParcelCollection
     */
    public function getMyparcelCollection()
    {
        return $this->myparcel_collection;
    }

    /**
     * @param $order Order
     */
    public function addOrder($order)
    {
        $this->orders[$order->getId()] = $order;
    }

    /**
     * Set existing or create new Magento track and set API consignment to collection
     *
     * @param $downloadLabel bool
     * @param $packageType   int
     *
     * @throws \Exception
     * @throws \Magento\Framework\Exception\LocalizedException on
     */
    public function setMagentoAndMyParcelTrack($downloadLabel, $packageType)
    {
        /** @var $magentoTrack Order\Shipment\Track */
        foreach ($this->orders as $order) {
            $postNLTrack = null;

            if ($downloadLabel && !empty($order->getTracksCollection())) {
                // Use existing track
                foreach ($order->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() ==
                        MyParcelTrackTrace::POSTNL_CARRIER_CODE &&
                        $magentoTrack->getData('myparcel_consignment_id')
                    ) {
                        $postNLTrack = (new MyParcelTrackTrace($this->_objectManager, $this->helper))
                            ->setApiKey($this->helper->getGeneralConfig('api/key'))
                            ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
                            ->setReferenceId($magentoTrack->getEntityId())
                            ->setPackageType($packageType);
                        $this->myparcel_collection->addConsignment($postNLTrack);
                    }
                }
            }

            if ($postNLTrack == null) {
                // Create new API consignment
                $postNLTrack = (new MyParcelTrackTrace($this->_objectManager, $this->helper))
                    ->createTrackTraceFromOrder($order)
                    ->convertDataFromMagentoToApi()
                    ->setPackageType($packageType);
                $this->myparcel_collection->addConsignment($postNLTrack);
            }
        }
    }

    /**
     * Update all the tracks that made created via the API
     */
    public function updateMagentoTrack()
    {
        /** @var $magentoTrack Order\Shipment\Track */
        foreach ($this->myparcel_collection->getConsignments() as $postNLTrack) {
            $magentoTrack = $this->modelTrack->load($postNLTrack->getReferenceId());

            $magentoTrack->setData('myparcel_consignment_id', $postNLTrack->getMyParcelConsignmentId());
            $magentoTrack->setData('myparcel_status', $postNLTrack->getStatus());

            if ($postNLTrack->getBarcode()) {
                $magentoTrack->setTrackNumber($postNLTrack->getBarcode());
            }

            $magentoTrack->save();
        }

        $this->updateOrderGrid();
    }

    /**
     * Update column track_status in sales_order_grid
     */
    private function updateOrderGrid()
    {
        foreach ($this->orders as $orderId => &$order) {
            $aHtml = $this->getHtmlForGridColumns($order);
            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
            $order->save();
        }
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

        /** @var $shipment Order\Shipment */
        foreach ($order->getShipmentsCollection() as $shipment) {
            // Set all track data in array
            foreach ($shipment->getTracks() as $track) {
                if ($track->getData('myparcel_status')) {
                    $data['track_status'][] = __('status_' . $track->getData('myparcel_status'));
                }
                if ($track->getData('track_number')) {
                    $data['track_number'][] = $this->getTrackUrl($shipment, $track->getData('track_number'));
                }
            }
        }

        // Create html
        if ($data['track_status']) {
            $columnHtml['track_status'] = implode('<br>', $data['track_status']);
        }
        if ($data['track_number']) {
            $columnHtml['track_number'] = implode('<br>', $data['track_number']);
        }

        return $columnHtml;
    }

    /**
     * @param $shipment Order\Shipment
     * @param $trackNumber string
     *
     * @return string
     */
    private function getTrackUrl($shipment, $trackNumber)
    {
        $address = $shipment->getShippingAddress();

        if ($address->getCountryId() != 'NL' || $trackNumber == 'concept') {
            return $trackNumber;
        }

        $url =
            self::URL_SHOW_POSTNL_STATUS .
            '?b=' . $trackNumber .
            '&p=' . $address->getPostcode();
        $link = '<a onclick="window.open(\'' . $url . '\', \'_blank\');">' . $trackNumber . "</a>";

        return $link;
    }
}