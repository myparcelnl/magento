<?php

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Module\Manager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\OrderLineOptionsFromOrderAdapter;
use MyParcelNL\Magento\Model\Source\SourceItem;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Helper\SplitStreet;
use MyParcelNL\Sdk\src\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\src\Model\Recipient;
use MyParcelNL\Sdk\src\Support\Collection;

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
     * @var \MyParcelNL\Magento\Model\Source\SourceItem
     */
    private $sourceItem = null;

    /**
     * @var \Magento\Framework\Module\Manager
     */
    private $moduleManager;

    private $order;

    /**
     * @var \MyParcelNL\Sdk\src\Model\Recipient
     */
    private $billingRecipient;

    /**
     * @var \MyParcelNL\Sdk\src\Model\Recipient
     */
    private $shippingRecipient;

    /**
     * @param  ObjectManagerInterface                   $objectManager
     * @param  \Magento\Framework\App\RequestInterface  $request
     * @param  null                                     $areaList
     */
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
     * @param \Magento\Sales\Model\ResourceModel\Order\Collection|Order[] $orderCollection
     *
     * @return $this
     */
    public function setOrderCollection($orderCollection): self
    {
        $this->orders = $orderCollection;

        return $this;
    }

    /**
     * Set Magento collection
     *
     * @return $this
     */
    public function reload(): self
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
     * @return $this
     * @throws \Exception
     *
     */
    public function setFulfilment()
    {
        $apiKey          = $this->getApiKey();
        $orderCollection = (new OrderCollection())->setApiKey($apiKey);
        $orderLines      = new Collection();

        foreach ($this->getOrders() as $magentoOrder) {
            $myparcelDeliveryOptions = $magentoOrder['myparcel_delivery_options'];
            $deliveryOptions         = json_decode($myparcelDeliveryOptions, true);
            $deliveryOptionsAdapter  = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
            $this->order             = $magentoOrder;

            $this->setBillingRecipient();
            $this->setShippingRecipient();

            $order = (new FulfilmentOrder())
                ->setStatus($this->order->getStatus())
                ->setDeliveryOptions($deliveryOptionsAdapter)
                ->setInvoiceAddress($this->getBillingRecipient())
                ->setRecipient($this->getShippingRecipient())
                ->setOrderDate($this->helper->convertDeliveryDate($this->order->getCreatedAt()))
                ->setExternalIdentifier($this->order->getIncrementId());

            foreach ($this->order->getItems() as $magentoOrderItem) {
                $orderLine = new OrderLineOptionsFromOrderAdapter($magentoOrderItem);

                $orderLines->push($orderLine);
            }

            $order->setOrderLines($orderLines);
            $orderCollection->push($order);
        }

        $this->myParcelCollection = $orderCollection->save();

        return $this;
    }

    /**
     * @return self
     */
    public function setBillingRecipient(): self
    {
        $this->billingRecipient = (new Recipient())
            ->setCc($this->order->getBillingAddress()->getCountryId())
            ->setCity($this->order->getBillingAddress()->getCity())
            ->setCompany($this->order->getBillingAddress()->getCompany())
            ->setEmail($this->order->getBillingAddress()->getEmail())
            ->setPerson($this->getFullCustomerName())
            ->setPhone($this->order->getBillingAddress()->getTelephone())
            ->setPostalCode($this->order->getBillingAddress()->getPostcode())
            ->setStreet(implode(' ', $this->order->getBillingAddress()->getStreet()));

        return $this;
    }

    /**
     * @return \MyParcelNL\Sdk\src\Model\Recipient|null
     */
    public function getBillingRecipient(): ?Recipient
    {
        return $this->billingRecipient;
    }

    /**
     * @return self
     * @throws \Exception
     */
    public function setShippingRecipient(): self
    {
        $myparcelDeliveryOptions  = $this->order['myparcel_delivery_options'] ?? '';
        $formattedDeliveryOptions = json_decode($myparcelDeliveryOptions, true);
        $carrier                  = ConsignmentFactory::createByCarrierName($formattedDeliveryOptions['carrier']);
        $street                   = implode(
            ' ',
            $this->order->getShippingAddress()
                ->getStreet()
        );

        $country     = $this->order->getShippingAddress()->getCountryId();
        $streetParts = SplitStreet::splitStreet($street, $carrier->getLocalCountryCode(), $country);

        $this->shippingRecipient = (new Recipient())
            ->setCc($country)
            ->setCity($this->order->getShippingAddress()->getCity())
            ->setCompany($this->order->getShippingAddress()->getCompany())
            ->setEmail($this->order->getShippingAddress()->getEmail())
            ->setPerson($this->getFullCustomerName())
            ->setPostalCode($this->order->getShippingAddress()->getPostcode())
            ->setStreet($streetParts->getStreet())
            ->setNumber($streetParts->getNumber())
            ->setNumberSuffix($streetParts->getNumberSuffix())
            ->setBoxNumber($streetParts->getBoxNumber());

        return $this;
    }

    /**
     * @return \MyParcelNL\Sdk\src\Model\Recipient|null
     */
    public function getShippingRecipient(): ?Recipient
    {
        return $this->shippingRecipient;
    }

    /**
     * @return string
     */
    public function getFullCustomerName(): string
    {
        $firstName  = $this->order->getBillingAddress()->getFirstname();
        $middleName = $this->order->getBillingAddress()->getMiddlename();
        $lastName   = $this->order->getBillingAddress()->getLastname();

        return $firstName . ' ' . $middleName . ' ' . $lastName;
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
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
     */
    protected function getShipmentsCollection(): \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
    {
        $orderIds = [];
        foreach ($this->getOrders() as $order) {
            $orderIds[] = $order->getEntityId();
        }

        $shipmentsCollection = $this->objectManager->get(MagentoShipmentCollection::PATH_MODEL_SHIPMENT);
        $shipmentsCollection->addAttributeToFilter('order_id', ['in' => $orderIds]);

        return $shipmentsCollection;
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

        $shipmentAttributes = $shipment->getExtensionAttributes();

        if (method_exists($shipmentAttributes, 'setSourceCode')) {
            $shipmentAttributes->setSourceCode($this->sourceItem->getSource($order, $order->getAllItems()));
            $shipment->setExtensionAttributes($shipmentAttributes);
        }

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

            if (preg_match('/' . MagentoOrderCollection::DEFAULT_ERROR_ORDER_HAS_NO_SOURCE . '/', $e->getMessage())) {
                $this->messageManager->addErrorMessage(__(MagentoOrderCollection::ERROR_ORDER_HAS_NO_SOURCE));
            } else {
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }

            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($e);
        }
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
