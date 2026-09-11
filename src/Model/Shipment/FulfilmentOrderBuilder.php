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

        return (new Recipient())
            ->setCc($billingAddress->getCountryId())
            ->setCity($billingAddress->getCity())
            ->setCompany($billingAddress->getCompany())
            ->setEmail($billingAddress->getEmail())
            ->setPerson($this->fullCustomerName($magentoOrder))
            ->setPhone($billingAddress->getTelephone())
            ->setPostalCode($billingAddress->getPostcode())
            ->setStreet(implode(' ', $billingAddress->getStreet() ?? []));
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

        return (new Recipient())
            ->setCc($country)
            ->setCity($shippingAddress->getCity())
            ->setCompany($shippingAddress->getCompany())
            ->setEmail($shippingAddress->getEmail())
            ->setPerson($this->fullCustomerName($magentoOrder))
            ->setPostalCode($shippingAddress->getPostcode())
            ->setStreet($streetParts->getStreet())
            ->setNumber((string) $streetParts->getNumber())
            ->setNumberSuffix((string) $streetParts->getNumberSuffix())
            ->setBoxNumber((string) $streetParts->getBoxNumber())
            ->setPhone($shippingAddress->getTelephone());
    }

    private function fullCustomerName(Order $magentoOrder): string
    {
        $billingAddress = $magentoOrder->getBillingAddress();

        if (! $billingAddress) {
            return '';
        }

        $firstName  = $billingAddress->getFirstname();
        $middleName = $billingAddress->getMiddlename();
        $lastName   = $billingAddress->getLastname();

        return "$firstName $middleName $lastName";
    }

    /** @throws RuntimeException when the options say pickup but carry no location */
    private function pickupLocation(DeliveryOptions $deliveryOptions): PickupLocation
    {
        $location = $deliveryOptions->getPickupLocation();

        if (null === $location) {
            throw new RuntimeException('This order is a pickup but carries no pickup location');
        }

        return new PickupLocation([
            'cc'                => $location->getCountry(),
            'city'              => $location->getCity(),
            'postal_code'       => $location->getPostalCode(),
            'street'            => $location->getStreet(),
            'number'            => $location->getNumber(),
            'location_name'     => $location->getLocationName(),
            'location_code'     => $location->getLocationCode(),
            'retail_network_id' => $location->getRetailNetworkId(),
        ]);
    }

    private function localCreatedAtDate(Order $magentoOrder, string $format = 'Y-m-d H:i:s'): string
    {
        $scopeConfig = $this->objectManager->create(ScopeConfigInterface::class);
        $datetime    = DateTime::createFromFormat('Y-m-d H:i:s', $magentoOrder->getCreatedAt());
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
