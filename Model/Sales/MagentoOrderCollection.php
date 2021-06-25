<?php
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Source\ReturnInTheBox;
use MyParcelNL\Magento\Model\Source\SourceItem;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

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
     * @var SourceItem
     */
    private $sourceItem = null;

    /**
     * @var Manager
     */
    private $moduleManager;

    public function __construct(ObjectManagerInterface $objectManager, $request = null, $areaList = null)
    {
        parent::__construct($objectManager, $request, $areaList);

        $this->objectManager = $objectManager;
        $this->moduleManager = $objectManager->get(Manager::class);

        $this->setSourceItemWhenInventoryApiEnabled();
    }

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
     * @param $orderCollection \Magento\Sales\Model\ResourceModel\Order\Collection|Order[]
     *
     * @return $this
     */
    public function setOrderCollection($orderCollection)
    {
        $this->orders = $orderCollection;

        return $this;
    }

    /**
     * Set Magento collection
     *
     * @return $this
     */
    public function reload()
    {
        $ids = $this->orders->getAllIds();

        $orders = [];
        foreach ($ids as $orderId) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $orders[]      = $objectManager->create('\Magento\Sales\Model\Order')->load($orderId);
        }

        $this->setOrderCollection($orders);

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

        $this->save();

        return $this;
    }

    /**
     * Create new Magento Track and save order
     *
     * @return $this
     * @throws \Exception
     */
    public function setMagentoTrack()
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getShipmentsCollection() as $shipment) {
            $i = 1;

            if (
                $this->shipmentHasTrack($shipment) == false ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                while ($i <= $this->getOption('label_amount')) {
                    $this->setNewMagentoTrack($shipment);
                    $i++;
                }
            }
        }

        $this->save();

        return $this;
    }

    /**
     * Add MyParcel Track from Magento Track
     *
     * @return $this
     * @throws \Exception
     *
     */
    public function setMyParcelTrack()
    {
        $newCollection = new MyParcelCollection();

        /**
         * @var Order                $order
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($this->getOrders() as $order) {
            if ($order->getShipmentsCollection()->getSize() == 0) {
                $this->messageManager->addErrorMessage(self::ERROR_ORDER_HAS_NO_SHIPMENT);
            }
            foreach ($order->getShipmentsCollection() as $shipment) {
                foreach ($shipment->getTracksCollection() as $magentoTrack) {
                    if ($magentoTrack->getCarrierCode() == TrackTraceHolder::MYPARCEL_CARRIER_CODE) {
                        $trackTraceHolder = $this->createConsignmentAndGetTrackTraceHolder($magentoTrack);
                    }
                }
                if (! empty($trackTraceHolder)) {
                    $consignment = $trackTraceHolder->consignment->setReferenceId($shipment->getEntityId());
                    $newCollection->addMultiCollo($consignment, $this->getOption('label_amount'));
                }
            }
        }

        $this->myParcelCollection = $newCollection;

        if ($this->options['return_in_the_box']) {
            $this->addreturnInTheBox($this->options['return_in_the_box']);
        }

        return $this;
    }

    /**
     * Set PDF content and convert status 'Concept' to 'Registered'
     *
     * @return $this
     * @throws \Exception
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
     * @param string $returnOptions
     *
     * @return void
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function addReturnInTheBox(string $returnOptions): void
    {
        $this->myParcelCollection
            ->generateReturnConsignments(
                false,
                function (
                    AbstractConsignment $returnConsignment,
                    AbstractConsignment $parent
                ) use ($returnOptions): AbstractConsignment {
                    $returnConsignment->setLabelDescription(
                        'Return: ' . $parent->getLabelDescription() .
                        ' This label is valid until: ' . date("d-m-Y", strtotime("+ 28 days"))
                    );

                    if (ReturnInTheBox::NO_OPTIONS === $returnOptions) {
                        $returnConsignment->setOnlyRecipient(false);
                        $returnConsignment->setSignature(false);
                        $returnConsignment->setAgeCheck(false);
                        $returnConsignment->setReturn(false);
                        $returnConsignment->setLargeFormat(false);
                        $returnConsignment->setInsurance(false);
                    }

                    return $returnConsignment;
                }
            );
    }

    /**
     * Create MyParcel concepts and update Magento Track
     *
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
     */
    public function createMyParcelConcepts()
    {
        if (! count($this->myParcelCollection)) {
            $this->messageManager->addWarningMessage(__('myparcelnl_magento_error_no_shipments_to_process'));
            return $this;
        }

        try {
            $this->myParcelCollection->createConcepts();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this;
        }

        $this->myParcelCollection->setLatestData();

        /**
         * @var Order                $order
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $mageTrack
         */
        foreach ($this->getShipmentsCollection() as $shipment) {
            $consignments = $this->myParcelCollection->getConsignmentsByReferenceId($shipment->getEntityId());
            foreach ($shipment->getTracksCollection() as $mageTrack) {
                if (! $consignment = $consignments->pop()) {
                    continue;
                }

                $mageTrack
                    ->setData('myparcel_consignment_id', $consignment->getConsignmentId())
                    ->setData('myparcel_status', $consignment->getStatus())
                    ->save(); // must
            }
        }

        return $this;
    }

    /**
     * Update MyParcel collection
     *
     * @return $this
     * @throws \Exception
     */
    public function setLatestData()
    {
        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function sendReturnLabelMails()
    {
        $this->myParcelCollection->generateReturnConsignments(true);

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
     * Update all the tracks that made created via the API
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateMagentoTrack(): self
    {
        foreach ($this->getShipmentsCollection() as $shipment) {
            $trackCollection = $shipment->getAllTracks();
            foreach ($trackCollection as $magentoTrack) {
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId(
                    $magentoTrack->getData('myparcel_consignment_id')
                );

                if (! $myParcelTrack) {
                    continue;
                }

                if ($myParcelTrack->getStatus()) {
                    $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());
                }

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
    public function updateGridByOrder(): self
    {
        if (empty($this->getOrders())) {
            throw new LocalizedException(__('MagentoOrderCollection::order array is empty'));
        }

        foreach ($this->getOrders() as $order) {
            $aHtml = $this->getHtmlForGridColumns($order->getId());

            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
        }

        $this->save();

        return $this;
    }

    /**
     * Check if there is 1 shipment in all orders
     *
     * @return bool
     */
    public function hasShipment(): bool
    {
        foreach ($this->getOrders() as $order) {
            if ($order->hasShipments()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array
     */
    private function getShipmentsCollection(): array
    {
        if (! isset($this->orders)) {
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
     * return void
     */
    private function save(): void
    {
        foreach ($this->getOrders() as $order) {
            $order->save();
        }
    }

    /**
     * Send shipment email with Track and trace variable
     *
     * @param \Magento\Sales\Model\Order $order
     *
     * @return $this
     */
    private function sendTrackEmailFromOrder(Order $order): self
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
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function createShipment(Order $order)
    {
        /**
         * @var Order\Shipment                     $shipment
         * @var \Magento\Sales\Model\Convert\Order $convertOrder
         */
        // Initialize the order shipment object
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        $shipment     = $convertOrder->toShipment($order);

        // Loop through order items
        foreach ($order->getAllItems() as $orderItem) {
            // Check if order item has qty to ship or is virtual
            if (! $orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped = $orderItem->getQtyToShip();

            // Create shipment item with qty
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);

            // Add shipment item to shipment
            $shipment->addItem($shipmentItem);

            if ($this->sourceItem) {
                $source = $this->getMultiStockInventory($orderItem);
                $shipment->getExtensionAttributes()->setSourceCode($source);
            }
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
     * @param \Magento\Sales\Model\Order\Item $orderItem
     *
     * @return string
     */
    private function getMultiStockInventory(\Magento\Sales\Model\Order\Item $orderItem): string
    {
        $sku    = $orderItem->getSku();
        $result = $this->sourceItem->getSourceItemDetailBySKU($sku);

        foreach ($result as $item) {
            if ($item->getSourceCode() !== 'default') {
                return $item->getSourceCode();
            }
        }

        return 'default';
    }

    /**
     * Check if the module Magento_InventoryApi is activated.
     * Some customers have removed the Magento_InventoryApi from their system.
     * That causes problems with the Multi Stock Inventory
     *
     * @return void
     */
    private function setSourceItemWhenInventoryApiEnabled(): void
    {
        if ($this->moduleManager->isEnabled('Magento_InventoryApi')) {
            $this->sourceItem = $this->objectManager->get(SourceItem::class);
        }
    }
}
