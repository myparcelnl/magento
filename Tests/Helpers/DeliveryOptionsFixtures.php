<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\PickupLocation;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * Delivery options fixtures for the REST layer tests. Real value objects, not doubles, so a fixture
 * cannot express a state production cannot reach. Overrides are keyed the way the data is stored.
 */

/**
 * $data with $overrides applied. An unknown key throws, so a typo cannot pass silently.
 *
 * @param array<string, mixed> $data
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function withStoredOverrides(array $data, array $overrides): array
{
    foreach ($overrides as $key => $value) {
        if (! array_key_exists($key, $data)) {
            throw new InvalidArgumentException("No stored key is known as '$key'");
        }

        $data[$key] = $value;
    }

    return $data;
}

/**
 * @param array<string, mixed> $overrides keyed by stored name, e.g. ['signature' => true]
 *
 * @return array<string, mixed> keyed the way the options are stored
 */
function shipmentOptionsData(array $overrides = []): array
{
    $data = array_fill_keys(ShipmentOption::having('toggle'), false) + [
        ShipmentOption::SAME_DAY_DELIVERY  => false,
        ShipmentOption::INSURANCE          => null,
        ShipmentOptions::LABEL_DESCRIPTION => null,
    ];

    return withStoredOverrides($data, $overrides);
}

function shipmentOptionsFixture(array $overrides = []): ShipmentOptions
{
    return ShipmentOptions::of(shipmentOptionsData($overrides));
}

/**
 * @param array<string, mixed> $overrides keyed by stored name, e.g. ['cc' => 'BE']
 *
 * @return array<string, mixed> keyed the way a pickup location is stored
 */
function pickupLocationData(array $overrides = []): array
{
    $data = [
        'location_code'     => 'LOC-1',
        'location_name'     => 'Test location',
        'retail_network_id' => 'NET-1',
        'street'            => 'Main street',
        'number'            => '42',
        'postal_code'       => '1234AB',
        'city'              => 'Amsterdam',
        'cc'                => 'NL',
    ];

    return withStoredOverrides($data, $overrides);
}

function pickupLocationFixture(array $overrides = []): PickupLocation
{
    return PickupLocation::fromCheckoutData(pickupLocationData($overrides));
}

/**
 * Every field the versioned REST response can carry. Pickup, because that is the only delivery type
 * that can carry a pickup location.
 */
function fullDeliveryOptions(array $shipmentOverrides = []): DeliveryOptions
{
    return DeliveryOptions::fromCheckoutData([
        'carrier'             => 'postnl',
        'packageType'         => PackageType::PACKAGE_NAME,
        'deliveryType'        => DeliveryType::PICKUP_NAME,
        'date'                => date('Y-m-d', strtotime('+7 days')),
        'shipmentOptions'     => shipmentOptionsData(array_merge([
            'signature'         => true,
            'only_recipient'    => true,
            'insurance'         => 50,
            'label_description' => 'PO-12345',
        ], $shipmentOverrides)),
        'pickupLocation' => pickupLocationData(),
    ]);
}
