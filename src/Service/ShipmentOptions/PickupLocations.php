<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPickupLocations;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPickupLocationsDefinitionsPickupLocation as SdkLocation;
use RuntimeException;
use Throwable;

/**
 * The pickup locations `GET /pickup_locations` returns near an order's shipping address, for one carrier.
 *
 * Each location comes in the keys PickupLocation::fromCheckoutData() reads, plus its distance and
 * its earliest pickup date, so a chosen one is stored in the same shape as a checkout pickup.
 * Parcel lockers are left out on the checkout's rule, or when the order has an age check.
 */
class PickupLocations
{
    private ShipmentApiProvider  $apiProvider;
    private ShippingAddressQuery $addressQuery;
    private CartShippingRules    $cartRules;

    public function __construct(
        ShipmentApiProvider  $apiProvider,
        ShippingAddressQuery $addressQuery,
        CartShippingRules    $cartRules
    ) {
        $this->apiProvider  = $apiProvider;
        $this->addressQuery = $addressQuery;
        $this->cartRules    = $cartRules;
    }

    /**
     * @return array<int,array{location_code:string,location_name:string,retail_network_id:string,street:string,number:string,postal_code:string,city:string,cc:string,distance:string,date:?string}>
     * @throws RuntimeException when the locations cannot be read
     */
    public function near(Order $order, string $carrier): array
    {
        $query     = $this->addressQuery->forOrder($order);
        $carrierId = Carrier::idFor($carrier);

        if (null === $carrierId) {
            throw new RuntimeException(sprintf('carrier "%s" is not one the SDK knows', $carrier));
        }

        try {
            $response = $this->apiProvider->clientFor($this->apiProvider->apiKeyForStore((int) $order->getStoreId()))->getPickupLocations(
                $query['cc'],
                $query['postal_code'],
                $query['number'],
                $query['city'],
                $query['street'],
                $query['platform'],
                null,
                (string) $carrierId,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                $this->excludesParcelLockers($order, $carrier) ? 1 : null
            );
        } catch (Throwable $e) {
            throw new RuntimeException('the pickup locations could not be requested: ' . $e->getMessage(), 0, $e);
        }

        if (! $response instanceof ShipmentResponsesPickupLocations) {
            throw new RuntimeException('the pickup locations request was refused');
        }

        return array_map([self::class, 'location'], $response->getData()->getPickupLocations() ?? []);
    }

    /**
     * One location near the order's shipping address, by its code, or null when the API does not
     * return it.
     *
     * @throws RuntimeException when the locations cannot be read
     */
    public function find(Order $order, string $carrier, string $locationCode): ?array
    {
        foreach ($this->near($order, $carrier) as $location) {
            if ($locationCode === $location['location_code']) {
                return $location;
            }
        }

        return null;
    }

    private function excludesParcelLockers(Order $order, string $carrier): bool
    {
        $stored = json_decode((string) $order->getData(Config::FIELD_DELIVERY_OPTIONS), true);

        return true === ($stored['shipmentOptions'][ShipmentOption::AGE_CHECK] ?? null)
               || $this->cartRules->excludesParcelLockers(
                   $order->getAllVisibleItems(),
                   Config::carrierPath($carrier),
                   (int) $order->getStoreId()
               );
    }

    private static function location(SdkLocation $location): array
    {
        $dates = [];

        foreach ($location->getPossibilities() ?? [] as $possibility) {
            $start = $possibility->getMoment() ? $possibility->getMoment()->getStart() : null;

            if (null !== $start && $start->getDate()) {
                $dates[] = substr($start->getDate(), 0, 10);
            }
        }

        $address = $location->getAddress();
        $details = $location->getLocation();

        return [
            'location_code'     => (string) $details->getLocationCode(),
            'location_name'     => (string) $details->getLocationName(),
            'retail_network_id' => (string) $details->getRetailNetworkId(),
            'street'            => (string) $address->getStreet(),
            'number'            => (string) $address->getNumber(),
            'postal_code'       => (string) $address->getPostalCode(),
            'city'              => (string) $address->getCity(),
            'cc'                => (string) $address->getCc(),
            'distance'          => (string) $details->getDistance(),
            'date'              => [] === $dates ? null : min($dates),
        ];
    }
}
