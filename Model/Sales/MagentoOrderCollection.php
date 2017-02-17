<?php
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Model\Repository\MyParcelConsignmentRepository;

/**
 * Class MagentoOrderCollection
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoOrderCollection
{
    const PATH_HELPER_DATA = 'MyParcelNL\Magento\Helper\Data';
    const PATH_MODEL_ORDER = '\Magento\Sales\Model\ResourceModel\Order\Collection';
    const PATH_ORDER_GRID = '\Magento\Sales\Model\ResourceModel\Order\Grid\Collection';
    const PATH_ORDER_TRACK = 'Magento\Sales\Model\Order\Shipment\Track';
    const URL_SHOW_POSTNL_STATUS = 'https://mijnpakket.postnl.nl/Inbox/Search';

    /**
     * @var MyParcelCollection
     */
    public $myParcelCollection;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private $orders;

    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order\Shipment\Track
     */
    private $modelTrack;

    /**
     * @var \Magento\Framework\App\AreaList
     */
    private $areaList;

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param ObjectManagerInterface $objectManagerInterface
     * @param null                   $areaList
     */
    public function __construct(ObjectManagerInterface $objectManagerInterface, $areaList = null)
    {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager = $objectManagerInterface;

        $this->helper = $objectManagerInterface->create(self::PATH_HELPER_DATA);
        $this->modelTrack = $objectManagerInterface->create(self::PATH_ORDER_TRACK);
        $this->myParcelCollection = new MyParcelCollection();
    }

    /**
     * @param $myParcelConsignment MyParcelConsignmentRepository
     *
     * @return $this
     * @throws \Exception
     */
    public function addMyParcelConsignment($myParcelConsignment)
    {
        $this->myParcelCollection->addConsignment($myParcelConsignment);

        return $this;
    }

    /**
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrders()
    {
        return $this->orders;
    }

    /**
     * @param $orderCollection \Magento\Sales\Model\ResourceModel\Order\Collection
     *
     * @return $this
     */
    public function setOrderCollection($orderCollection)
    {
        $this->orders = $orderCollection;

        return $this;
    }

    /**
     * Set existing or create new Magento track and set API consignment to collection
     *
     * @param $requestType   string
     * @param $packageType   int
     *
     * @throws \Exception
     * @throws LocalizedException
     */
    public function setMagentoAndMyParcelTrack($requestType, $packageType)
    {
        /** @var $order Order */
        foreach ($this->getOrders() as $order) {

            if ($order->canShip()) {
                $this->createShipment($order);
            }

            /*if ($requestType && !empty($order->getTracksCollection())) {
                // Use existing track
                foreach ($order->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() == MyParcelTrackTrace::MYPARCEL_CARRIER_CODE &&
                        $magentoTrack->getData('myparcel_consignment_id')
                    ) {
                        $myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper))
                            ->setApiKey($this->helper->getGeneralConfig('api/key'))
                            ->setMyParcelConsignmentId($magentoTrack->getData('myparcel_consignment_id'))
                            ->setReferenceId($magentoTrack->getEntityId())
                            ->setPackageType($packageType);
                        $this->myParcelCollection->addConsignment($myParcelTrack);
                    }
                }
            }*/

            // Create new API consignment
            /*$myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper))
                ->createTrackTraceFromOrder($order)
                ->convertDataFromMagentoToApi()
                ->setPackageType($packageType);
            $this->myParcelCollection->addConsignment($myParcelTrack);
            */
        }
    }

    /**
     * This create a shipment. Obsserver/NewShipment() create Magento and MyParcel Track
     *
     * @param Order $order
     *
     * @return $this
     * @throws LocalizedException
     */
    private function createShipment(Order $order)
    {
        /**
         * @var Order\Shipment $shipment
         */
        // Initialize the order shipment object
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        $shipment = $convertOrder->toShipment($order);

        // Loop through order items
        foreach ($order->getAllItems() as $orderItem) {
            // Check if order item has qty to ship or is virtual
            if (!$orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped = $orderItem->getQtyToShip();

            // Create shipment item with qty
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);

            // Add shipment item to shipment
            $shipment->addItem($shipmentItem);
        }

        // Register shipment
        $shipment->register();

        $shipment->getOrder()->setIsInProcess(true);

        try {
            // Save created shipment and order
            $shipment->save();

            // Send email
            $this->objectManager->create('Magento\Shipping\Model\ShipmentNotifier')
                ->notify($shipment);

        } catch (\Exception $e) {
            throw new LocalizedException(
                __($e->getMessage())
            );
        }
        return $this;
    }

    public function updateMyParcelTrackFromMagentoOrder()
    {
        /**
         * @var Order $order
         * @var Order\Shipment\Track $track
         */
        foreach ($this->getOrders() as $order) {
            foreach ($order->getTracksCollection() as $track) {
                $consignment = (new MyParcelConsignmentRepository())
                    ->setApiKey($this->helper->getGeneralConfig('api/key'))
                    ->setMyParcelConsignmentId($track->getData('myparcel_consignment_id'))
                    ->setReferenceId($track->getId());
                $this->myParcelCollection->addConsignment($consignment);
            }
        }
    }

    /**
     * Update all the tracks that made created via the API
     */
    public function updateMagentoTrack()
    {
        /**
         * @var $order Order
         * @var $magentoTrack Order\Shipment\Track
         */
        foreach ($this->getOrders() as &$order) {
            var_dump('test2: ' . $order->getTracksCollection()->getSize());
            foreach ($order->getTracksCollection() as &$magentoTrack) {
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId($magentoTrack->getData('myparcel_consignment_id'));

                $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());

                if ($myParcelTrack->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                }
            }
        }

        $this->updateOrderGrid();
    }

    /**
     * Update column track_status in sales_order_grid
     */
    private function updateOrderGrid()
    {
        if (empty($this->orders)) {
            throw new LocalizedException(__('MagentoOrderCollection::order array is empty'));
        }

        foreach ($this->orders as $orderId => $order) {
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
     * @throws \Exception
     */
    private function getHtmlForGridColumns($order)
    {
        /**
         * @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
         */
        // Temporarily fix to translate in cronjob
        if (!empty($this->areaList)) {
            $areaObject = $this->areaList->getArea(\Magento\Framework\App\Area::AREA_ADMINHTML);
            $areaObject->load(\Magento\Framework\App\Area::PART_TRANSLATE);
        }

        if ($order->getTracksCollection()->getSize() == 0) {
            throw new LocalizedException(__('Tracks collection is empty. Order id:' . $order->getId()));
        } else {
            var_dump('order id: ' . $order->getId());
        }

        $data = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];

        /** @var $track Order\Shipment\Track */
        foreach ($order->getTracksCollection() as $track) {
            // Set all track data in array
            if ($track->getData('myparcel_status')) {
                $data['track_status'][] = __('status_' . $track->getData('myparcel_status'));
            }
            if ($track->getData('track_number')) {
                $data['track_number'][] = $this->getTrackUrl($track->getShipment(), $track->getData('track_number'));
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
     * @param $shipment    Order\Shipment
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
