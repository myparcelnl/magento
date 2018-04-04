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

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoOrderCollection extends MagentoCollection
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private $orders = null;

    /**
     * Get all Magento orders
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrders()
    {
        return $this->orders;
    }

    /**
     * Set Magento collection
     *
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
     * Set existing or create new Magento Track and set API consignment to collection
     *
     * @throws \Exception
     * @throws LocalizedException
     */
    public function setNewMagentoShipment()
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
     * Create new Magento Track and save order
     *
     * @return $this
     */
    public function setMagentoTrack()
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getShipmentsCollection() as $shipment) {
            if ($this->shipmentHasTrack($shipment) == false ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                $this->setNewMagentoTrack($shipment);
            }
        }

        $this->getOrders()->save();

        return $this;
    }

    /**
     * @return array|\Magento\Sales\Model\ResourceModel\order\shipment\Collection
     */
    private function getShipmentsCollection()
    {
        if ($this->orders == null) {
            return [];
        }

        $shipments = [];
        foreach ($this->getOrders() as $order) {
            foreach ($order->getShipmentsCollection() as $shipment) {
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    /**
     * Add MyParcel Track from Magento Track
     *
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
            if ($order->getShipmentsCollection()->getSize() == 0) {
                $this->messageManager->addError(self::ERROR_ORDER_HAS_NO_SHIPMENT);
            }
            foreach ($order->getShipmentsCollection() as $shipment) {
                foreach ($shipment->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() == MyParcelTrackTrace::MYPARCEL_CARRIER_CODE) {
                        $myparcelTrack = $this->getMyParcelTrack($magentoTrack);
                        $this->myParcelCollection->addConsignment($myparcelTrack);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Set PDF content and convert status 'Concept' to 'Registered'
     *
     * @return $this
     */
    public function setPdfOfLabels()
    {
        $this->myParcelCollection->setPdfOfLabels($this->options['positions']);

        return $this;
    }

    /**
     * Download PDF directly
     *
     * @return $this
     * @throws \Exception
     */
    public function downloadPdfOfLabels()
    {
        $inlineDownload = $this->options['request_type'] == 'open_new_tab';
        $this->myParcelCollection->downloadPdfOfLabels($inlineDownload);

        return $this;
    }

    /**
     * Create MyParcel concepts and update Magento Track
     *
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
        foreach ($this->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                $myParcelTrack = $this
                    ->myParcelCollection->getConsignmentByReferenceId($track->getId());

                $track
                    ->setData('myparcel_consignment_id', $myParcelTrack->getMyParcelConsignmentId())
                    ->setData('myparcel_status', $myParcelTrack->getStatus())
                    ->save(); // must
            }
        }

        return $this;
    }

    /**
     * Update MyParcel collection
     *
     * @return $this
     */
    public function setLatestData()
    {
        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * @return $this
     */
    public function sendReturnLabelMails()
    {
        $this->myParcelCollection->sendReturnLabelMails();

        return $this;
    }

    /**
     * Send multiple shipment emails with Track and trace variable
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
     * Send shipment email with Track and trace variable
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     */
    private function sendTrackEmailFromOrder(Order $order)
    {
        /**
         * @var \Magento\Sales\Model\Order\Shipment $shipment
         */
        if ($this->trackSender->isEnabled() == false) {
            return $this;
        }

        foreach ($order->getShipmentsCollection() as $shipment) {
            if ($shipment->getEmailSent() == null) {
                $this->trackSender->send($shipment);
            }
        }

        return $this;
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
    }

    /**
     * Update all the tracks that made created via the API
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateMagentoTrack()
    {
        /**
         * @var $order        Order
         * @var $shipment     Order\Shipment
         * @var $magentoTrack Order\Shipment\Track
         */
        foreach ($this->getShipmentsCollection() as $shipment) {

            $trackCollection = $shipment->getAllTracks();
            foreach ($trackCollection as $magentoTrack) {
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId(
                    $magentoTrack->getData('myparcel_consignment_id')
                );

                $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());

                if ($myParcelTrack->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                }
                $magentoTrack->save();
            }
        }

        $this->updateGridByOrder();

        return $this;
    }

    /**
     * Update column track_status in sales_order_grid
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateGridByOrder()
    {
        if (empty($this->getOrders())) {
            throw new LocalizedException(__('MagentoOrderCollection::order array is empty'));
        }

        /**
         * @var Order $order
         */
        foreach ($this->getOrders() as $order) {
            $aHtml = $this->getHtmlForGridColumns($order->getId());

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
     * Check if there is 1 shipment in all orders
     *
     * @return bool
     */
    public function hasShipment()
    {
        /** @var $order Order */
        /** @var Order\Shipment $shipment */
        foreach ($this->getOrders() as $order) {
            if ($order->hasShipments()) {
                return true;
            }
        }

        return false;
    }
}
