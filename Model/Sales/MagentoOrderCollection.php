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
 *
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


    public function printCountTrackCollection($note)
    {

        foreach ($this->getOrders() as $order) {
            /**
             * @var Order $order
             * @var Order\Shipment $shipment
             */
            foreach ($order->getShipmentsCollection() as $shipment) {
                if ($shipment->getTracksCollection()->count() == 0) {
                    exit(('!! Hier !!! Tracks collection is empty. Order id:' . $order->getId()) . $note);
                }
            }
        }

        return $this;
    }

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
     * @throws \Exception
     * @throws LocalizedException
     */
    public function setMagentoShipment()
    {
        /** @var $order Order */
        /** @var Order\Shipment $shipment */
        foreach ($this->getOrders() as &$order) {
            if ($order->canShip()) {
                $this->createShipment($order);
                $order->save();
                $this->getOrders()->removeItemByKey($order->getId())->addItem($order)->save();
            }
        }

        $this->getOrders()->save();

        return $this;
    }

    /**
     * @param bool $createIfOneAlreadyExists Create track if one already exists
     *
     * @return $this
     * @todo; add filter can ship
     */
    public function setMagentoTrack($createIfOneAlreadyExists = false)
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getOrders() as &$order) {
            foreach ($order->getShipmentsCollection() as &$shipment) {
                if ($shipment->getTracksCollection()->count() == 0 || $createIfOneAlreadyExists) {
                    $this->setNewMagentoTrack($shipment);
                }
            }
        }

        $this->getOrders()->save();

        return $this;
    }

    /**
     * @param Order\Shipment $shipment
     *
     * @return mixed
     */
    private function setNewMagentoTrack(&$shipment)
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Track $track */
        /*$track = $this->objectManager->get(
            'Magento\Sales\Model\Order\Shipment\TrackFactory'
        )->create();*/
        $track = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(MyParcelTrackTrace::MYPARCEL_CARRIER_CODE)
            ->setTitle(MyParcelTrackTrace::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('concept')
            ->save();

        $shipment->addTrack($track);
        $shipment->getOrder()->save();

        return $track;
    }

    /**
     * @return $this
     * @throws \Exception
     *
     * @todo; add filter carrier code
     */
    public function setMyParcelTrack()
    {
        /**
         * @var Order                $order
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($this->getOrders() as $order) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                foreach ($shipment->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() == MyParcelTrackTrace::MYPARCEL_CARRIER_CODE) {
                        $this->myParcelCollection->addConsignment($this->getNewMyParcelTrack($magentoTrack));
                    }
                }
            }
        }

        return $this;
    }

    public function createMyParcelConcepts()
    {
        $this->myParcelCollection->createConcepts()->setLatestData();

        /**
         * @var Order                $order
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $track
         */
        foreach ($this->getOrders() as $order) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                foreach ($shipment->getTracksCollection() as $track) {
                    $myParcelTrack = $this
                        ->myParcelCollection->getConsignmentByReferenceId($track->getId());
                    $track
                        ->setData('myparcel_consignment_id', $myParcelTrack->getMyParcelConsignmentId())
                        ->setData('myparcel_status', $myParcelTrack->getStatus());
                }
            }
        }

        return $this;
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return MyParcelTrackTrace $myParcelTrack
     *
     * @todo ->setPackageType($packageType)
     */
    private function getNewMyParcelTrack($magentoTrack)
    {
        $myParcelTrack = (new MyParcelTrackTrace($this->objectManager, $this->helper, $magentoTrack->getShipment()->getOrder()))
            ->convertDataFromMagentoToApi($magentoTrack);

        return $myParcelTrack;
    }


    /**
     * This create a shipment. Observer/NewShipment() create Magento and MyParcel Track
     *
     * @param Order $order
     *
     * @return $this
     * @throws LocalizedException
     */
    private function createShipment(Order &$order)
    {
        /**
         * @var Order\Shipment                     $shipment
         * @var \Magento\Sales\Model\Convert\Order $convertOrder
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

        $transaction = $this->objectManager->create('Magento\Framework\DB\Transaction');
        $transaction->addObject($shipment)->addObject($shipment->getOrder())->save();
        try {
            // Save created shipment and order
//            $shipment->save();

//            $order->getShipmentsCollection()->addItem($shipment);
            $order->getCollection()->save();
            $shipment->getOrder()->getCollection()->save();
            // Send email
            $this->objectManager->create('Magento\Shipping\Model\ShipmentNotifier')
                ->notify($shipment);

        } catch (\Exception $e) {
            throw new LocalizedException(
                __($e->getMessage())
            );
        }

        return $shipment;
    }

    /**
     * Update all the tracks that made created via the API
     */
    public function updateMagentoTrack()
    {
        /**
         * @var $order        Order
         * @var $shipment     Order\Shipment
         * @var $magentoTrack Order\Shipment\Track
         */
        foreach ($this->getOrders() as &$order) {
            foreach ($order->getShipmentsCollection() as &$shipment) {
                foreach ($shipment->getTracksCollection() as &$magentoTrack) {
                    $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId($magentoTrack->getData('myparcel_consignment_id'));

                    $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());

                    if ($myParcelTrack->getBarcode()) {
                        $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                    }
                    $magentoTrack->save();
                }
            }
        }

        $this->getOrders()->save();

        $this->updateOrderGrid();
    }

    /**
     * Update column track_status in sales_order_grid
     */
    public function updateOrderGrid()
    {
        if (empty($this->getOrders())) {
            throw new LocalizedException(__('MagentoOrderCollection::order array is empty'));
        }
        /**
         * @var Order $order
         */

        foreach ($this->getOrders() as $order) {
            $aHtml = $this->getHtmlForGridColumns($order);
            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
        }
        $this->getOrders()->save();

        return $this;
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

        $data = ['track_status' => [], 'track_number' => []];
        $columnHtml = ['track_status' => '', 'track_number' => ''];


        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $track
         */
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                // Set all track data in array
                if ($track->getData('myparcel_status')) {
                    $data['track_status'][] = __('status_' . $track->getData('myparcel_status'));
                }
                if ($track->getData('track_number')) {
                    $data['track_number'][] = $this->getTrackUrl($track->getShipment(), $track->getData('track_number'));
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
