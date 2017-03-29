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

use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\magento\Model\Order\Email\Sender\TrackSender;
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
    const PATH_ORDER_TRACK_COLLECTION = '\Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection';
    const URL_SHOW_POSTNL_STATUS = 'https://mijnpakket.postnl.nl/Inbox/Search';

    /**
     * @var MyParcelCollection
     */
    public $myParcelCollection;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    public $request = null;

    /**
     * @var TrackSender
     */
    private $trackSender;

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

    private $options = [
        'create_track_if_one_already_exist' => true,
        'request_type' => 'download',
        'package_type' => 1,
        'positions' => null,
        'only_recipient' => null,
        'signature' => null,
        'return' => null,
        'large_format' => null,
        'insurance' => null,
    ];

    /**
     * CreateAndPrintMyParcelTrack constructor.
     *
     * @param ObjectManagerInterface                            $objectManagerInterface
     * @param \Magento\Framework\App\RequestInterface           $request
     * @param null                                              $areaList
     */
    public function __construct(ObjectManagerInterface $objectManagerInterface, $request = null, $areaList = null)
    {
        // @todo; Adjust if there is a solution to the following problem: https://github.com/magento/magento2/pull/8413
        if ($areaList) {
            $this->areaList = $areaList;
        }

        $this->objectManager = $objectManagerInterface;
        $this->request = $request;
        $this->trackSender = $this->objectManager->get('MyParcelNL\magento\Model\Order\Email\Sender\TrackSender');

        $this->helper = $objectManagerInterface->create(self::PATH_HELPER_DATA);
        $this->modelTrack = $objectManagerInterface->create(self::PATH_ORDER_TRACK);
        $this->myParcelCollection = new MyParcelCollection();
    }

    /**
     * @return $this
     */
    public function setOptionsFromParameters()
    {
        // If options isset
        foreach (array_keys($this->options) as $option) {
            if ($this->request->getParam('mypa_' . $option) === null) {

                if ($this->request->getParam('mypa_extra_options_checkboxes_in_form') === null) {
                    // Use default options
                    $this->options[$option] = null;
                } else {
                    // Checkbox isset but false
                    $this->options[$option] = false;
                }
            } else {
                $this->options[$option] = $this->request->getParam('mypa_' . $option);
            }
        }

        // Remove position if paper size == A6
        if ($this->request->getParam('mypa_paper_size', 'A6') != 'A4') {
            $this->options['positions'] = null;
        }

        if ($this->request->getParam('mypa_request_type') == null) {
            $this->options['request_type'] = 'download';
        }

        if ($this->request->getParam('mypa_request_type') != 'concept') {
            $this->options['create_track_if_one_already_exist'] = false;
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param $option
     *
     * @return mixed
     */
    public function getOption($option)
    {
        return $this->options[$option];
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
        foreach ($this->getOrders() as $order) {
            if ($order->canShip()) {
                $this->createShipment($order);
            }
        }

        $this->getOrders()->save();

        return $this;
    }

    /**
     * @param bool $createIfOneAlreadyExists Create track if one already exists
     *
     * @return $this
     */
    public function setMagentoTrack()
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getOrders() as $order) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                if ($this->shipmentHasTrack($shipment) == false ||
                    $this->getOption('create_track_if_one_already_exist')
                ) {
                    $this->setNewMagentoTrack($shipment);
                }
            }
        }

        $this->getOrders()->save();

        return $this;
    }

    /**
     * @param $shipment
     *
     * @return bool
     */
    private function shipmentHasTrack($shipment)
    {
        /**
         * \Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection $collection
         *
         * @var Order\Shipment $shipment
         */
        $collection = $this->objectManager->get(self::PATH_ORDER_TRACK_COLLECTION);
        $collection
            ->clear()
            ->addAttributeToFilter('parent_id', $shipment->getId());

        return $collection->count() == 0 ? false : true;
    }

    /**
     * @param Order\Shipment $shipment
     *
     * @return mixed
     */
    private function setNewMagentoTrack($shipment)
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Track $track */
        $track = $this->objectManager->create('Magento\Sales\Model\Order\Shipment\Track');
        $track
            ->setOrderId($shipment->getOrderId())
            ->setShipment($shipment)
            ->setCarrierCode(MyParcelTrackTrace::MYPARCEL_CARRIER_CODE)
            ->setTitle(MyParcelTrackTrace::MYPARCEL_TRACK_TITLE)
            ->setQty($shipment->getTotalQty())
            ->setTrackNumber('Concept')
            ->save();

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
                        $this->myParcelCollection->addConsignment($this->getMyParcelTrack($magentoTrack));
                    }
                }
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setPdfOfLabels()
    {
        $this->myParcelCollection->setPdfOfLabels($this->options['positions']);

        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function downloadPdfOfLabels()
    {
        $this->myParcelCollection->downloadPdfOfLabels();

        return $this;
    }

    /**
     * @return $this
     */
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
                        ->setData('myparcel_status', $myParcelTrack->getStatus())
                        ->save(); // must
                }
            }
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function setLatestData()
    {
        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * Send multiple emails with track and trace variable
     *
     * @return $this
     */
    public function sendTrackEmails()
    {

        foreach ($this->getOrders() as $order) {
            $this->sendTrackEmailFromOrder($order);
        }

        return $this;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     *
     * @todo fill PostObject with more details from the order
     */
    private function sendTrackEmailFromOrder(Order $order)
    {
        /**
         * @var \Magento\Sales\Model\Order\Shipment $shipment
         */
        if ($this->trackSender->isEnabled()) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                if ($shipment->getEmailSent() == null) {
                    $this->trackSender->send($shipment);
                }
            }
        }
    }

    /**
     * @param Order\Shipment\Track $magentoTrack
     *
     * @return MyParcelTrackTrace $myParcelTrack
     */
    private function getMyParcelTrack($magentoTrack)
    {
        $myParcelTrack = new MyParcelTrackTrace(
            $this->objectManager,
            $this->helper,
            $magentoTrack->getShipment()->getOrder()
        );
        $myParcelTrack->convertDataFromMagentoToApi($magentoTrack, $this->options);

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
    private function createShipment(Order $order)
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

        try {
            // Save created shipment and order
            $transaction = $this->objectManager->create('Magento\Framework\DB\Transaction');
            $transaction->addObject($shipment)->addObject($shipment->getOrder())->save();

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
        foreach ($this->getOrders() as $order) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                $trackCollection = $shipment->getTracksCollection();
                foreach ($trackCollection as $magentoTrack) {
                    $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId($magentoTrack->getData('myparcel_consignment_id'));

                    $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());

                    if ($myParcelTrack->getBarcode()) {
                        $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                    }
                }
                $trackCollection->save();
            }
        }

        $this->updateOrderGrid();

        return $this;
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
                if ($track->getData('myparcel_status') !== null) {
                    $data['track_status'][] = __('status_' . $track->getData('myparcel_status'));
                }
                if ($track->getData('track_number')) {
                    $data['track_number'][] = $track->getData('track_number');
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
}
