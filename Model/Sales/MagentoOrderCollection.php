<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Model\Sales;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Shipment as ShipmentResource;
use Magento\Store\Model\ScopeInterface;
use MyParcelBE\Magento\Adapter\OrderLineOptionsFromOrderAdapter;
use MyParcelBE\Magento\Cron\UpdateStatus;
use MyParcelBE\Magento\Helper\CustomsDeclarationFromOrder;
use MyParcelBE\Magento\Helper\ShipmentOptions;
use MyParcelBE\Magento\Model\Source\DefaultOptions;
use MyParcelBE\Magento\Services\Normalizer\ConsignmentNormalizer;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\src\Collection\Fulfilment\OrderNotesCollection;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Helper\SplitStreet;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\src\Model\Fulfilment\OrderNote;
use MyParcelNL\Sdk\src\Model\PickupLocation;
use MyParcelNL\Sdk\src\Model\Recipient;
use MyParcelNL\Sdk\src\Support\Collection;
use MyParcelNL\Sdk\src\Support\Str;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelBE\Magento\Model\Sales
 */
class MagentoOrderCollection extends MagentoCollection
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    private $orders = null;

    /**
     * @var \Magento\Sales\Model\Order
     */
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
            $orders[]      = $objectManager->create(Order::class)->load($orderId);
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
    public function setNewMagentoShipment(bool $notifyClientsByEmail = true): MagentoOrderCollection
    {
        /** @var Order $order */
        /** @var Order\Shipment $shipment */
        foreach ($this->getOrders() as $order) {
            if ($order->canShip()) {
                $this->createMagentoShipment($order, $notifyClientsByEmail);
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
    public function setMagentoTrack(): MagentoOrderCollection
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */

        foreach ($this->getShipmentsCollection() as $shipment) {
            $i = 1;

            if (
                $this->shipmentHasTrack($shipment) === false ||
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
    public function setFulfilment(): self
    {
        $apiKey          = $this->getApiKey();
        $orderCollection = (new OrderCollection())->setApiKey($apiKey);
        $orderLines      = new Collection();

        foreach ($this->getOrders() as $magentoOrder) {
            $defaultOptions          = new DefaultOptions($magentoOrder, $this->helper);
            $myparcelDeliveryOptions = $magentoOrder['myparcel_delivery_options'] ?? '';
            $deliveryOptions         = json_decode($myparcelDeliveryOptions, true);
            $selectedCarrier         = $deliveryOptions['carrier'] ?? $this->options['carrier'] ?? CarrierPostNL::NAME;
            $shipmentOptionsHelper   = new ShipmentOptions(
                $defaultOptions,
                $this->helper,
                $magentoOrder,
                $this->objectManager,
                $selectedCarrier,
                $this->options
            );

            if ($deliveryOptions && $deliveryOptions['isPickup']) {
                $deliveryOptions['packageType'] = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
            }

            $deliveryOptions['shipmentOptions'] = $shipmentOptionsHelper->getShipmentOptions();

            try {
                // create new instance from known json
                $deliveryOptions['carrier'] = $selectedCarrier;
                $deliveryOptionsAdapter     = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
            } catch (\BadMethodCallException $e) {
                // create new instance from unknown json data
                $deliveryOptions                = (new ConsignmentNormalizer((array) $deliveryOptions))->normalize();
                $deliveryOptions['packageType'] = $deliveryOptions['packageType'] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
                $deliveryOptionsAdapter         = DeliveryOptionsAdapterFactory::create($deliveryOptions);
            }

            $this->order = $magentoOrder;

            $this->setBillingRecipient();
            $this->setShippingRecipient();
            $order = (new FulfilmentOrder())
                ->setStatus($this->order->getStatus())
                ->setDeliveryOptions($deliveryOptionsAdapter)
                ->setInvoiceAddress($this->getBillingRecipient())
                ->setRecipient($this->getShippingRecipient())
                ->setOrderDate($this->getLocalCreatedAtDate())
                ->setExternalIdentifier($this->order->getIncrementId())
                ->setDropOffPoint(
                    $this->helper->getDropOffPoint(
                        CarrierFactory::createFromName($deliveryOptionsAdapter->getCarrier())
                    )
                );

            if ($deliveryOptionsAdapter->isPickup()) {
                $pickupData     = $deliveryOptionsAdapter->getPickupLocation();
                $pickupLocation = new PickupLocation([
                    'cc'                => $pickupData->getCountry(),
                    'city'              => $pickupData->getCity(),
                    'postal_code'       => $pickupData->getPostalCode(),
                    'street'            => $pickupData->getStreet(),
                    'number'            => $pickupData->getNumber(),
                    'location_name'     => $pickupData->getLocationName(),
                    'location_code'     => $pickupData->getLocationCode(),
                    'retail_network_id' => $pickupData->getRetailNetworkId(),
                ]);
                $order->setPickupLocation($pickupLocation);
            }

            foreach ($this->order->getItems() as $magentoOrderItem) {
                $orderLine = new OrderLineOptionsFromOrderAdapter($magentoOrderItem);

                $orderLines->push($orderLine);
            }

            $order->setOrderLines($orderLines);

            if (! in_array($this->shippingRecipient->getCc(), AbstractConsignment::EURO_COUNTRIES, true)) {
                $customsDeclarationAdapter = new CustomsDeclarationFromOrder($this->order, $this->objectManager);
                $customsDeclaration        = $customsDeclarationAdapter->createCustomsDeclaration();
                $order->setCustomsDeclaration($customsDeclaration);
            }

            $order->setWeight($this->getTotalWeight());
            $orderCollection->push($order);
        }

        try {
            $this->myParcelCollection = $orderCollection->save();
            $this->setMagentoOrdersAsExported();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        try {
            $this->saveOrderNotes();
        } catch(\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this;
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    private function saveOrderNotes(): void
    {
        $notes = (new OrderNotesCollection())->setApiKey($this->getApiKey());

        $this->myParcelCollection->each(function(FulfilmentOrder $order) use ($notes) {

            $this->getAllNotesForOrder($order)->each(function(OrderNote $note) use ($notes) {
                try {
                    $note->validate();
                    $notes->push($note);
                } catch (\Exception $e) {
                    $this->messageManager->addWarningMessage(
                        sprintf(
                            'Note `%s` not exported. %s',
                            Str::limit($note->getNote(), 30),
                            $e->getMessage()
                        )
                    );
                }
            });
        });

        $notes->save();
    }

    private function getAllNotesForOrder(FulfilmentOrder $fulfilmentOrder): OrderNotesCollection
    {
        $notes        = new OrderNotesCollection();
        $orderUuid    = $fulfilmentOrder->getUuid();
        $magentoOrder = $this->objectManager->create(Order::class)
            ->loadByIncrementId($fulfilmentOrder->getExternalIdentifier());

        foreach ($magentoOrder->getStatusHistoryCollection() as $status) {
            if (! $status->getComment()) {
                continue;
            }

            $notes->push(
                new OrderNote([
                    'orderUuid' => $orderUuid,
                    'note'      => $status->getComment(),
                    'author'    => 'webshop',
                ])
            );
        }

        return $notes;
    }

    private function setMagentoOrdersAsExported(): void
    {
        foreach ($this->getOrders() as $magentoOrder) {
            $magentoOrder->setData('track_status', UpdateStatus::ORDER_STATUS_EXPORTED);

            $fulfilmentOrder = $this->myParcelCollection->first(function(FulfilmentOrder $order) use ($magentoOrder){
                return $order->getExternalIdentifier() === $magentoOrder->getIncrementId();
            });

            if ($fulfilmentOrder) {
                $magentoOrder->setData('myparcel_uuid', $fulfilmentOrder->getUuid());
            }

            $magentoOrder->setIsInProcess(true);
            $this->objectManager->get(OrderResource::class)->save($magentoOrder);
        }
    }

    /**
     * @return int weight in grams
     */
    private function getTotalWeight(): int
    {
        $totalWeight = 0;

        foreach ($this->order->getItems() as $item) {
            $product = $item->getProduct();

            if (! $product) {
                continue;
            }

            $totalWeight += $product->getWeight() * $item->getQtyOrdered();
        }

        return $this->helper->convertToGrams($totalWeight);
    }

    /**
     * @param  string $format
     *
     * @return string
     */
    public function getLocalCreatedAtDate(string $format = 'Y-m-d H:i:s'): string
    {
        $scopeConfig = $this->objectManager->create(ScopeConfigInterface::class);
        $datetime    = \DateTime::createFromFormat('Y-m-d H:i:s', $this->order->getCreatedAt());
        $timezone    = $scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            $this->order->getStoreId()
        );

        if ($timezone) {
            $storeTime = new \DateTimeZone($timezone);
            $datetime->setTimezone($storeTime);
        }

        return $datetime->format($format);
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
            ->setStreet(implode(' ', $this->order->getBillingAddress()->getStreet() ?? []));

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
        $carrier                  = ConsignmentFactory::createByCarrierName(CarrierPostNL::NAME);
        $street                   = implode(
            ' ',
            $this->order->getShippingAddress()
                ->getStreet() ?? []
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
            ->setNumber((string) $streetParts->getNumber())
            ->setNumberSuffix((string) $streetParts->getNumberSuffix())
            ->setBoxNumber((string) $streetParts->getBoxNumber())
            ->setPhone($this->order->getShippingAddress()->getTelephone());

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
    public function setPdfOfLabels(): self
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
    public function downloadPdfOfLabels(): self
    {
        $inlineDownload = 'open_new_tab' === $this->options['request_type'];
        $this->myParcelCollection->downloadPdfOfLabels($inlineDownload);

        return $this;
    }

    /**
     * Update MyParcel collection
     *
     * @return $this
     * @throws \Exception
     */
    public function setLatestData(): self
    {
        if ($this->myParcelCollection->isEmpty()) {
            return $this;
        }

        $this->myParcelCollection->setLatestData();

        return $this;
    }

    /**
     * @return $this
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException|\MyParcelNL\Sdk\src\Exception\AccountNotActiveException
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
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function createMagentoShipment(Order $order, bool $notifyClientByEmail = true): void
    {
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        $shipment     = $convertOrder->toShipment($order);

        $shipmentAttributes = $shipment->getExtensionAttributes();

        if (method_exists($shipmentAttributes, 'setSourceCode')) {
            $shipmentAttributes->setSourceCode($this->sourceItem->getSource($order, $order->getAllItems()));
            $shipment->setExtensionAttributes($shipmentAttributes);
        }

        foreach ($order->getAllItems() as $orderItem) {
            if (! $orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped = $orderItem->getQtyToShip();
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register();
        $shipment->getOrder()->setIsInProcess(true);

        try {
            $this->objectManager->get(ShipmentResource::class)->save($shipment);
            $this->objectManager->get(OrderResource::class)->save($shipment->getOrder());
        } catch (\Exception $e) {
            if (preg_match('/' . MagentoOrderCollection::DEFAULT_ERROR_ORDER_HAS_NO_SOURCE . '/', $e->getMessage())) {
                $this->messageManager->addErrorMessage(__(MagentoOrderCollection::ERROR_ORDER_HAS_NO_SOURCE));
            } else {
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }

            $this->objectManager->get('Psr\Log\LoggerInterface')->critical($e);
        }

        if ($notifyClientByEmail) {
            $this->objectManager->create('Magento\Shipping\Model\ShipmentNotifier')
                ->notify($shipment);
        }
    }
}
