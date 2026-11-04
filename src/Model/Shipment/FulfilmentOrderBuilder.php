<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use DateTime;
use DateTimeZone;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\OrderLineOptionsFromOrderAdapter;
use MyParcelNL\Magento\Helper\CustomsDeclarationFromOrder;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Helper\SplitStreet;
use MyParcelNL\Sdk\Model\Fulfilment\Order as FulfilmentOrder;
use MyParcelNL\Sdk\Model\PickupLocation;
use MyParcelNL\Sdk\Model\Recipient;
use MyParcelNL\Sdk\Support\Collection;
use RuntimeException;

/**
 * Turns one Magento order into a v11 fulfilment (PPS) order, tagged with its own store's API key.
 *
 * The counterpart of ShipmentBuilder: carrier, package type and the option set come from the
 * OrderShipmentOptions both share, and what is decided here is what only a PPS order needs — the
 * two Recipients, the order lines and the order weight.
 *
 * Trap: setCarrierId() is mandatory. Carrier is shipment-level data in the Order v1 payload, and
 * AbstractOrder::getCarrier() throws when it was never set.
 */
class FulfilmentOrderBuilder
{
    private ObjectManagerInterface $objectManager;
    private Weight                 $weight;
    private ShipmentApiProvider    $apiProvider;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
        $this->weight        = $objectManager->get(Weight::class);
        $this->apiProvider   = $objectManager->get(ShipmentApiProvider::class);
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
        $shippingRecipient = $this->shippingRecipient($magentoOrder, $shipmentOptions->carrierName());

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

        if (CountryCode::isRow((string) $shippingRecipient->getCc())) {
            $order->setCustomsDeclaration(
                (new CustomsDeclarationFromOrder($magentoOrder))->createCustomsDeclaration()
            );
        }

        return $order;
    }

    /** Built per order on purpose: one collection shared across a batch gives every later order the earlier orders' lines. */
    private function orderLines(Order $magentoOrder): Collection
    {
        $orderLines = new Collection();

        foreach ($magentoOrder->getItems() as $magentoOrderItem) {
            $orderLines->push(new OrderLineOptionsFromOrderAdapter($magentoOrderItem));
        }

        return $orderLines;
    }

    /** @throws RuntimeException when the order has no billing address to invoice */
    private function billingRecipient(Order $magentoOrder): Recipient
    {
        $billingAddress = $magentoOrder->getBillingAddress();

        if (! $billingAddress) {
            throw new RuntimeException('This order has no billing address, so it cannot be exported');
        }

        return $this->recipientFrom($billingAddress, $magentoOrder)
                    ->setStreet(implode(' ', $billingAddress->getStreet() ?? []));
    }

    /**
     * The fields both recipients share. The shipping one then replaces the street with its split
     * parts; everything above that is the same address read the same way.
     *
     * The person is the billing name for both, which is what the fulfilment path has always sent —
     * a gift order therefore carries the buyer's name, not the recipient's.
     *
     * @param \Magento\Sales\Model\Order\Address $address
     */
    private function recipientFrom($address, Order $magentoOrder): Recipient
    {
        return (new Recipient())
            ->setCc($address->getCountryId())
            ->setCity($address->getCity())
            ->setCompany($address->getCompany())
            ->setEmail($address->getEmail())
            ->setPerson($this->fullCustomerName($magentoOrder))
            ->setPhone($address->getTelephone())
            ->setPostalCode($address->getPostcode());
    }

    /**
     * The first country SplitStreet takes is the carrier's, which picks the split rule; the second
     * is the destination's.
     *
     * @throws RuntimeException when the order has no shipping address
     */
    private function shippingRecipient(Order $magentoOrder, ?string $carrier): Recipient
    {
        $shippingAddress = $magentoOrder->getShippingAddress();

        if (! $shippingAddress) {
            throw new RuntimeException('This order has no shipping address, so it cannot be exported');
        }

        $country     = $shippingAddress->getCountryId();
        $streetParts = SplitStreet::splitStreet(
            implode(' ', $shippingAddress->getStreet() ?? []),
            Carrier::localCountryCodeFor($carrier),
            $country
        );

        return $this->recipientFrom($shippingAddress, $magentoOrder)
                    ->setStreet($streetParts->getStreet())
                    ->setNumber((string) $streetParts->getNumber())
                    ->setNumberSuffix((string) $streetParts->getNumberSuffix())
                    ->setBoxNumber((string) $streetParts->getBoxNumber());
    }

    private function fullCustomerName(Order $magentoOrder): string
    {
        $billingAddress = $magentoOrder->getBillingAddress();

        if (! $billingAddress) {
            return '';
        }

        // Filtered, not interpolated: an empty middle name used to print as a double space.
        return implode(' ', array_filter([
            $billingAddress->getFirstname(),
            $billingAddress->getMiddlename(),
            $billingAddress->getLastname(),
        ], static fn($part): bool => '' !== trim((string) $part)));
    }

    /** @throws RuntimeException when the options say pickup but carry no location */
    private function pickupLocation(DeliveryOptions $deliveryOptions): PickupLocation
    {
        $location = $deliveryOptions->getPickupLocation();

        if (null === $location) {
            throw new RuntimeException('This order is a pickup but carries no pickup location');
        }

        return new PickupLocation($location->toArray());
    }

    private function localCreatedAtDate(Order $magentoOrder, string $format = 'Y-m-d H:i:s'): string
    {
        $scopeConfig = $this->objectManager->create(ScopeConfigInterface::class);
        $datetime    = DateTime::createFromFormat('Y-m-d H:i:s', (string) $magentoOrder->getCreatedAt());

        if (false === $datetime) {
            return (string) $magentoOrder->getCreatedAt();
        }

        $timezone    = $scopeConfig->getValue(
            'general/locale/timezone',
            ScopeInterface::SCOPE_STORES,
            $magentoOrder->getStoreId()
        );

        if ($timezone) {
            $datetime->setTimezone(new DateTimeZone($timezone));
        }

        return $datetime->format($format);
    }

    /** @return int weight in grams */
    private function totalWeightInGrams(Order $magentoOrder): int
    {
        $totalWeight = 0;

        foreach ($magentoOrder->getItems() as $item) {
            $product = $item->getProduct();

            if (! $product) {
                continue;
            }

            $totalWeight += $product->getWeight() * $item->getQtyOrdered();
        }

        return $this->weight->convertToGrams($totalWeight);
    }
}
