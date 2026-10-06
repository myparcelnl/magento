<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use DateTime;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\AccountSettings\AccountProposition;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Model\Fulfilment\OrderLine;
use MyParcelNL\Sdk\Model\PickupLocation;
use MyParcelNL\Sdk\Model\Recipient;
use MyParcelNL\Sdk\Services\CountryService;
use MyParcelNL\Sdk\Support\Collection;
use RuntimeException;

/**
 * Turns one Magento order into a v11 fulfilment (PPS) order, tagged with its own store's API key.
 *
 * The counterpart of ShipmentBuilder: carrier, package type and the option set come from the
 * OrderShipmentOptions both share, and what is decided here is what only a PPS order needs: the
 * two Recipients, the order lines and the order weight.
 *
 * Always call setCarrierId(): AbstractOrder::getCarrier() throws when the carrier was never set.
 */
class FulfilmentOrderBuilder
{
    private ObjectManagerInterface $objectManager;
    private Weight                 $weight;
    private ShipmentApiProvider    $apiProvider;
    private AccountProposition     $accountProposition;
    private RecipientFields        $recipientFields;
    private CountryService         $countryService;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager      = $objectManager;
        $this->weight             = $objectManager->get(Weight::class);
        $this->apiProvider        = $objectManager->get(ShipmentApiProvider::class);
        $this->recipientFields    = $objectManager->get(RecipientFields::class);
        $this->accountProposition = $objectManager->get(AccountProposition::class);
        $this->countryService     = $objectManager->get(CountryService::class);
    }

    /**
     * @throws LocalizedException when the order's store has no API key
     * @throws RuntimeException   when the order cannot be exported as stored
     */
    public function build(Order $magentoOrder, array $options): FulfilmentOrder
    {
        $shipmentOptions = new OrderShipmentOptions(
            $this->objectManager,
            $magentoOrder,
            $options,
            new DefaultOptions($magentoOrder)
        );

        $deliveryOptions   = $shipmentOptions->deliveryOptions();
        $shippingAddress   = $magentoOrder->getShippingAddress();
        $shippingRecipient = $this->shippingRecipient(
            $magentoOrder,
            $this->accountProposition->homeCountryForStore((int) $magentoOrder->getStoreId())
        );

        $order = (new FulfilmentOrder())
            ->setApiKey($this->apiProvider->apiKeyForStore((int) $magentoOrder->getStoreId()))
            ->setStatus((string) $magentoOrder->getStatus())
            ->setCarrierId($shipmentOptions->carrierId())
            ->setDeliveryOptions($shipmentOptions->shipmentOptions($shippingAddress))
            ->setInvoiceAddress($this->billingRecipient($magentoOrder))
            ->setRecipient($shippingRecipient)
            ->setOrderDate($this->localCreatedAtDate($magentoOrder))
            ->setExternalIdentifier((string) $magentoOrder->getIncrementId())
            ->setOrderLines($this->orderLines($magentoOrder))
            ->setWeight($this->totalWeightInGrams($magentoOrder));

        if ($deliveryOptions->isPickup()) {
            $order->setPickupLocation($this->pickupLocation($deliveryOptions));
        }

        if ($this->countryService->isRow((string) $shippingRecipient->getCc())) {
            $order->setCustomsDeclaration(
                $this->objectManager->get(OrderCustomsDeclarationBuilder::class)->build($magentoOrder)
            );
        }

        return $order;
    }

    /** Built per order on purpose: one collection shared across a batch gives every later order the earlier orders' lines. */
    private function orderLines(Order $magentoOrder): Collection
    {
        $orderLines = new Collection();

        /** @var \Magento\Sales\Model\Order\Item $magentoOrderItem getItems() declares the interface */
        foreach ($magentoOrder->getItems() as $magentoOrderItem) {
            $orderLines->push($this->orderLine($magentoOrderItem));
        }

        return $orderLines;
    }

    /**
     * One order row as an order line, every amount per unit as the SDK wants it.
     *
     * @param \Magento\Sales\Model\Order\Item $item
     */
    private function orderLine(DataObject $item): OrderLine
    {
        // price is per unit and tax_amount is per row
        $quantity = (float) $item->getQtyOrdered();
        $price    = DeliveryCosts::getPriceInCents((float) $item->getPrice());
        $tax      = (float) $item->getTaxAmount();
        $vat      = DeliveryCosts::getPriceInCents($quantity > 0.0 ? $tax / $quantity : $tax);
        $weight   = (float) $item->getWeight();
        $product  = $item->getProduct();

        // A product deleted from the catalog still ships: its dimensions are unknown then.
        $size = static fn(string $getter): int => $product ? (int) $product->$getter() : 0;

        return new OrderLine([
            'price'           => $price,
            'vat'             => $vat,
            'price_after_vat' => $price + $vat,
            'quantity'        => $item->getQtyOrdered(),
            'product'         => [
                'external_identifier' => (string) $item->getItemId(),
                'name'                => $item->getName(),
                'sku'                 => $item->getSku(),
                'height'              => $size('getHeight'),
                'length'              => $size('getLength'),
                // Not convertToGrams() alone: it gives a weightless row the default weight.
                'weight'              => $weight > 0.0 ? $this->weight->convertToGrams($weight) : 0,
                'width'               => $size('getWidth'),
                'description'         => $item->getDescription(),
            ],
        ]);
    }

    /** @throws RuntimeException when the order has no billing address to invoice */
    private function billingRecipient(Order $magentoOrder): Recipient
    {
        $billingAddress = $magentoOrder->getBillingAddress();

        if (! $billingAddress) {
            throw new RuntimeException((string) __('this order has no billing address, so it cannot be exported'));
        }

        return $this->recipientFrom($billingAddress)
                    ->setStreet(implode(' ', $billingAddress->getStreet() ?? []));
    }

    /**
     * Get the fields both recipients share, each from its own address. The shipping one then replaces
     * the street.
     *
     * @param \Magento\Sales\Model\Order\Address $address
     */
    private function recipientFrom($address): Recipient
    {
        return (new Recipient())
            ->setCc($address->getCountryId())
            ->setCity($address->getCity())
            ->setCompany($address->getCompany())
            ->setEmail($address->getEmail())
            ->setPerson($this->recipientFields->person($address))
            ->setPhone($address->getTelephone())
            ->setPostalCode($this->recipientFields->postalCode($address))
            ->setRegion($this->recipientFields->state($address));
    }

    /**
     * The first country SplitStreet takes is the account's home country, which picks the split rule;
     * the second is the destination's.
     *
     * @throws RuntimeException when the order has no shipping address
     */
    private function shippingRecipient(Order $magentoOrder, string $localCountry): Recipient
    {
        $shippingAddress = $magentoOrder->getShippingAddress();

        if (! $shippingAddress) {
            throw new RuntimeException((string) __('this order has no shipping address, so it cannot be exported'));
        }

        $country     = $shippingAddress->getCountryId();
        $streetParts = SplitStreet::splitStreet(
            implode(' ', $shippingAddress->getStreet() ?? []),
            $localCountry,
            $country
        );

        return $this->recipientFrom($shippingAddress)
                    ->setStreet($streetParts->getStreet())
                    ->setNumber((string) $streetParts->getNumber())
                    ->setNumberSuffix((string) $streetParts->getNumberSuffix())
                    ->setBoxNumber((string) $streetParts->getBoxNumber());
    }

    /** @throws RuntimeException when the options say pickup but carry no location */
    private function pickupLocation(DeliveryOptions $deliveryOptions): PickupLocation
    {
        $location = $deliveryOptions->getPickupLocation();

        if (null === $location) {
            throw new RuntimeException((string) __('this order is a pickup but carries no pickup location'));
        }

        return new PickupLocation($location->toArray());
    }

    private function localCreatedAtDate(Order $magentoOrder): string
    {
        $scopeConfig = $this->objectManager->create(ScopeConfigInterface::class);
        $datetime    = DateTime::createFromFormat('Y-m-d H:i:s', (string) $magentoOrder->getCreatedAt());

        if (false === $datetime) {
            return (string) $magentoOrder->getCreatedAt();
        }

        $timezone = $scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORES,
            $magentoOrder->getStoreId()
        );

        if ($timezone) {
            $datetime->setTimezone(new DateTimeZone($timezone));
        }

        return $datetime->format('Y-m-d H:i:s');
    }

    /** @return int weight in grams, of the same rows the customs declaration counts */
    private function totalWeightInGrams(Order $magentoOrder): int
    {
        $totalWeight = 0.0;

        /** @var \Magento\Sales\Model\Order\Item $item getItems() declares the interface */
        foreach ($magentoOrder->getItems() as $item) {
            if (! CustomsItems::isShippedRow($item)) {
                continue;
            }

            $totalWeight += (float) $item->getWeight() * (float) $item->getQtyOrdered();
        }

        return $this->weight->convertToGrams($totalWeight);
    }
}
