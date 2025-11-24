<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Sales;

use BadMethodCallException;
use DateTime;
use DateTimeZone;
use Exception;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\Shipment as ShipmentResource;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Service\Normalizer\ConsignmentNormalizer;
use MyParcelNL\Magento\Adapter\OrderLineOptionsFromOrderAdapter;
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Helper\CustomsDeclarationFromOrder;
use MyParcelNL\Magento\Helper\ShipmentOptions;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderCollection;
use MyParcelNL\Sdk\Collection\Fulfilment\OrderNotesCollection;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Model\Fulfilment\OrderNote;
use MyParcelNL\Sdk\Model\PickupLocation;
use MyParcelNL\Sdk\Model\Recipient;
use MyParcelNL\Sdk\Support\Collection;
use MyParcelNL\Sdk\Support\Str;
use Throwable;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\ShipmentNotifier;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoOrderCollection extends MagentoCollection
{
    /**
     * @var null|OrderResource\Collection|Order[]
     */
    private $orders = null;
    private Order $order;
    private Recipient $billingRecipient;
    private Recipient $shippingRecipient;
    private Ordercollection $fulfilmentCollection;

    /**
     * Get all Magento orders
     *
     * @return OrderResource\Collection|Order[]
     */
    public function getOrders()
    {
        return $this->orders;
    }

    /**
     * Set Magento collection
     *
     * @param OrderResource\Collection|Order[] $orderCollection
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
            $objectManager = ObjectManager::getInstance();
            $orders[]      = $objectManager->create(Order::class)->load($orderId);
        }

        $this->setOrderCollection($orders);

        return $this;
    }

    /**
     * Set existing or create new Magento Track and set API consignment to collection
     *
     * @throws Exception
     * @throws LocalizedException
     */
    public function setNewMagentoShipment(bool $notifyClientsByEmail = true): MagentoOrderCollection
    {
        /** @var Order $order */
        foreach ($this->getOrders() as $order) {
            if ($order->canShip() && $this->createMagentoShipment($order, $notifyClientsByEmail)) {
                $order->setIsInProcess(true);
            }
        }

        $this->save();

        return $this;
    }

    /**
     * Create new Magento Track and save order
     *
     * @return $this
     * @throws Exception
     */
    public function setMagentoTrack(): MagentoOrderCollection
    {
        /**
         * @var Shipment $shipment
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
     * @throws Exception
     *
     */
    public function setFulfilment(): self
    {
        $orderCollection = new OrderCollection();
        $orderLines      = new Collection();

        foreach ($this->getOrders() as $magentoOrder) {
            $defaultOptions          = new DefaultOptions($magentoOrder);
            $myparcelDeliveryOptions = $magentoOrder[Config::FIELD_DELIVERY_OPTIONS] ?? '';
            $deliveryOptions         = json_decode($myparcelDeliveryOptions, true);
            $selectedCarrier         = $deliveryOptions['carrier'] ?? $this->options['carrier'] ?? CarrierPostNL::NAME;
            $shipmentOptionsHelper   = new ShipmentOptions(
                $defaultOptions,
                $magentoOrder,
                $this->objectManager,
                $selectedCarrier,
                $this->options
            );

            if ($deliveryOptions && $deliveryOptions['isPickup']) {
                $deliveryOptions['packageType'] = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
            }

            $deliveryOptions['shipmentOptions'] = $shipmentOptionsHelper->getShipmentOptions();
            $deliveryOptions['carrier']         = $selectedCarrier;

            try {
                // create new instance from known json
                $deliveryOptionsAdapter = DeliveryOptionsAdapterFactory::create((array) $deliveryOptions);
            } catch (BadMethodCallException $e) {
                // create new instance from unknown json data
                $deliveryOptions['packageType']  = $deliveryOptions['packageType'] ?? AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;
                $deliveryOptions['deliveryType'] = $deliveryOptions['deliveryType'] ?? AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME;
                $deliveryOptionsAdapter          = DeliveryOptionsAdapterFactory::create($deliveryOptions);
            }

            $this->order = $magentoOrder;

            $this->setBillingRecipient();
            $this->setShippingRecipient();

            $apiKey = $this->config->getGeneralConfig('api/key', (int) $magentoOrder->getStoreId());
            $dropOffPoint = (new AccountSettings($apiKey))->getDropOffPoint(
                CarrierFactory::createFromName($deliveryOptionsAdapter->getCarrier())
            );

            $order = (new FulfilmentOrder())
                ->setApiKey($apiKey)
                ->setStatus($this->order->getStatus())
                ->setDeliveryOptions($deliveryOptionsAdapter)
                ->setInvoiceAddress($this->getBillingRecipient())
                ->setRecipient($this->getShippingRecipient())
                ->setOrderDate($this->getLocalCreatedAtDate())
                ->setExternalIdentifier($this->order->getIncrementId())
                ->setDropOffPoint($dropOffPoint)
            ;

            if ($deliveryOptionsAdapter->isPickup()
                && ($pickupData = $deliveryOptionsAdapter->getPickupLocation())
            ) {
                $pickupLocation = new PickupLocation(
                    [
                        'cc'                => $pickupData->getCountry(),
                        'city'              => $pickupData->getCity(),
                        'postal_code'       => $pickupData->getPostalCode(),
                        'street'            => $pickupData->getStreet(),
                        'number'            => $pickupData->getNumber(),
                        'location_name'     => $pickupData->getLocationName(),
                        'location_code'     => $pickupData->getLocationCode(),
                        'retail_network_id' => $pickupData->getRetailNetworkId(),
                    ]
                );
                $order->setPickupLocation($pickupLocation);
            }

            foreach ($this->order->getItems() as $magentoOrderItem) {
                $orderLine = new OrderLineOptionsFromOrderAdapter($magentoOrderItem);

                $orderLines->push($orderLine);
            }

            $order->setOrderLines($orderLines);

            if (! in_array($this->shippingRecipient->getCc(), AbstractConsignment::EURO_COUNTRIES, true)) {
                $customsDeclarationAdapter = new CustomsDeclarationFromOrder($this->order);
                $customsDeclaration        = $customsDeclarationAdapter->createCustomsDeclaration();
                $order->setCustomsDeclaration($customsDeclaration);
            }

            $order->setWeight($this->getTotalWeight());
            $orderCollection->push($order);
        }

        try {
            $this->fulfilmentCollection = $orderCollection->save();
            $this->setMagentoOrdersAsExported();
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        try {
            $this->saveOrderNotes();
        } catch (Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this;
    }

    private function saveOrderNotes(): void
    {
        $this->fulfilmentCollection->each(function (FulfilmentOrder $order) {

            $notes = new OrderNotesCollection();

            $this->getAllNotesForOrder($order)->each(function (OrderNote $note) use ($notes) {
                try {
                    $note->validate();
                    $notes->push($note);
                } catch (Throwable $e) {
                    $this->messageManager->addWarningMessage(
                        sprintf(
                            'Note `%s` not exported. %s',
                            Str::limit($note->getNote(), 30),
                            $e->getMessage()
                        )
                    );
                }
            });

            $notes->save($order->getApiKey());
        });
    }

    private function getAllNotesForOrder(FulfilmentOrder $fulfilmentOrder): OrderNotesCollection
    {
        $notes        = new OrderNotesCollection();
        $orderUuid    = $fulfilmentOrder->getUuid();
        $magentoOrder = $this->objectManager->create(Order::class)
                                            ->loadByIncrementId($fulfilmentOrder->getExternalIdentifier())
        ;

        foreach ($magentoOrder->getStatusHistoryCollection() as $status) {
            if (! $status->getComment()) {
                continue;
            }

            $notes->push(
                new OrderNote(
                    [
                        'orderUuid' => $orderUuid,
                        'note'      => $status->getComment(),
                        'author'    => 'webshop',
                    ]
                )
            );
        }

        return $notes;
    }

    private function setMagentoOrdersAsExported(): void
    {
        foreach ($this->getOrders() as $magentoOrder) {
            $magentoOrder->setData('track_status', UpdateStatus::ORDER_STATUS_EXPORTED);

            $fulfilmentOrder = $this->fulfilmentCollection->first(function (FulfilmentOrder $order) use ($magentoOrder) {
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

        return $this->weight->convertToGrams($totalWeight);
    }

    /**
     * @param string $format
     *
     * @return string
     */
    public function getLocalCreatedAtDate(string $format = 'Y-m-d H:i:s'): string
    {
        $scopeConfig = $this->objectManager->create(ScopeConfigInterface::class);
        $datetime    = DateTime::createFromFormat('Y-m-d H:i:s', $this->order->getCreatedAt());
        $timezone    = $scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORE,
            $this->order->getStoreId()
        );

        if ($timezone) {
            $storeTime = new DateTimeZone($timezone);
            $datetime->setTimezone($storeTime);
        }

        return $datetime->format($format);
    }

    /**
     * @return self
     */
    public function setBillingRecipient(): self
    {
        $billingAddress = $this->order->getBillingAddress();

        if (! $billingAddress) {
            return $this;
        }

        $this->billingRecipient = (new Recipient())
            ->setCc($billingAddress->getCountryId())
            ->setCity($billingAddress->getCity())
            ->setCompany($billingAddress->getCompany())
            ->setEmail($billingAddress->getEmail())
            ->setPerson($this->getFullCustomerName())
            ->setPhone($billingAddress->getTelephone())
            ->setPostalCode($billingAddress->getPostcode())
            ->setStreet(implode(' ', $billingAddress->getStreet() ?? []))
        ;

        return $this;
    }

    /**
     * @return Recipient|null
     */
    public function getBillingRecipient(): ?Recipient
    {
        return $this->billingRecipient;
    }

    /**
     * @return self
     * @throws Exception
     */
    public function setShippingRecipient(): self
    {
        $shippingAddress = $this->order->getShippingAddress();

        if (! $shippingAddress) {
            return $this;
        }

        $carrier = ConsignmentFactory::createByCarrierName(CarrierPostNL::NAME);
        $street  = implode(
            ' ',
            $shippingAddress->getStreet() ?? []
        );

        $country     = $shippingAddress->getCountryId();
        $streetParts = SplitStreet::splitStreet($street, $carrier->getLocalCountryCode(), $country);

        $this->shippingRecipient = (new Recipient())
            ->setCc($country)
            ->setCity($shippingAddress->getCity())
            ->setCompany($shippingAddress->getCompany())
            ->setEmail($shippingAddress->getEmail())
            ->setPerson($this->getFullCustomerName())
            ->setPostalCode($shippingAddress->getPostcode())
            ->setStreet($streetParts->getStreet())
            ->setNumber((string) $streetParts->getNumber())
            ->setNumberSuffix((string) $streetParts->getNumberSuffix())
            ->setBoxNumber((string) $streetParts->getBoxNumber())
            ->setPhone($shippingAddress->getTelephone())
        ;

        return $this;
    }

    /**
     * @return Recipient|null
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
        $billingAddress = $this->order->getBillingAddress();

        if (! $billingAddress) {
            return '';
        }

        $firstName  = $billingAddress->getFirstname();
        $middleName = $billingAddress->getMiddlename();
        $lastName   = $billingAddress->getLastname();

        return "$firstName $middleName $lastName";
    }

    /**
     * Set PDF content and convert status 'Concept' to 'Registered'
     *
     * @return $this
     * @throws Exception
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
     * @throws Exception
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
     * @throws Exception
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
     * @throws ApiException
     * @throws MissingFieldException|AccountNotActiveException
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
     * @return ShipmentResource\Collection
     */
    protected function getShipmentsCollection(): ShipmentResource\Collection
    {
        $orderIds = [];
        foreach ($this->getOrders() as $order) {
            $orderIds[] = $order->getEntityId();
        }

        $shipmentsCollection = $this->objectManager->get(MagentoShipmentCollection::PATH_MODEL_SHIPMENT_COLLECTION);
        $shipmentsCollection->addAttributeToFilter('order_id', ['in' => $orderIds]);

        return $shipmentsCollection;
    }

    /**
     * return void
     */
    private function save(): void
    {
        $resourceManager = $this->objectManager->get(OrderResource::class);

        foreach ($this->getOrders() as $order) {
            $resourceManager->save($order);
        }
    }

    /**
     * Send shipment email with Track and trace variable
     *
     * @param Order $order
     *
     * @return $this
     */
    private function sendTrackEmailFromOrder(Order $order): self
    {
        /**
         * @var Shipment $shipment
         */
        if (! $this->trackSender->isEnabled()) {
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
     * @throws AlreadyExistsException
     */
    public function createMagentoShipment(Order $order, bool $notifyClientByEmail = true): bool
    {
        $convertOrder = $this->objectManager->create('Magento\Sales\Model\Convert\Order');
        /** @var Shipment $shipment */
        $shipment = $convertOrder->toShipment($order);

        $shipmentAttributes = $shipment->getExtensionAttributes();

        if ($shipmentAttributes && method_exists($shipmentAttributes, 'setSourceCode')) {
            $shipmentAttributes->setSourceCode($this->sourceItem->getSource($order, $order->getAllItems()));
            $shipment->setExtensionAttributes($shipmentAttributes);
        }

        foreach ($order->getAllItems() as $orderItem) {
            if (! $orderItem->getQtyToShip() || $orderItem->getIsVirtual()) {
                continue;
            }

            $qtyShipped   = $orderItem->getQtyToShip();
            $shipmentItem = $convertOrder->itemToShipmentItem($orderItem)->setQty($qtyShipped);
            $shipment->addItem($shipmentItem);
        }

        $shipment->register(); // here the items are set to shipped in table sales_order_item

        try {
            $this->objectManager->get(ShipmentResource::class)->save($shipment);
        } catch (Throwable $e) {
            if (preg_match('/' . self::DEFAULT_ERROR_ORDER_HAS_NO_SOURCE . '/', $e->getMessage())) {
                $this->messageManager->addErrorMessage(__(self::ERROR_ORDER_HAS_NO_SOURCE));
            } else {
                $this->messageManager->addErrorMessage(__($e->getMessage()));
            }

            /**
             * Prevent not being able to ship an order even though no shipment was saved here:
             * undo the set shipment quantity update that $shipment->register did before the exception
             */
            foreach ($shipment->getAllItems() as $item) {
                $orderItem = $item->getOrderItem();
                $orderItem->setQtyShipped($orderItem->getQtyShipped() - $item->getQty());
            }
            $this->objectManager->get(OrderResource::class)->save($shipment->getOrder());

            $this->objectManager->get(LoggerInterface::class)->critical($e);

            return false; // well that didn’t work
        }

        if ($notifyClientByEmail) {
            $this->objectManager->create(ShipmentNotifier::class)
                                ->notify($shipment)
            ;
        }

        return true;
    }
}
