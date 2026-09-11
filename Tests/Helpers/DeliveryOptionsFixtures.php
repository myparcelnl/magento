<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\PickupLocation;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;

/**
 * Delivery options fixtures for the REST layer tests. Real value objects, not doubles, so a fixture
 * cannot express a state production cannot reach.
 *
 * Overrides are keyed by getter name and translated to stored keys here, because that is how the
 * tests were already written.
 */

/**
 * @param array<string, mixed> $overrides keyed by getter name, e.g. ['hasSignature' => true]
 *
 * @return array<string, mixed> keyed the way the options are stored
 */
function shipmentOptionsData(array $overrides = []): array
{
    $storedKeyForGetter = [
        'hasAgeCheck'         => 'age_check',
        'hasSignature'        => 'signature',
        'hasOnlyRecipient'    => 'only_recipient',
        'hasLargeFormat'      => 'large_format',
        'hasReturn'           => 'return',
        'hasHideSender'       => 'hide_sender',
        'hasPriorityDelivery' => 'priority_delivery',
        'hasReceiptCode'      => 'receipt_code',
        'hasSameDayDelivery'  => 'same_day_delivery',
        'hasCollect'          => 'collect',
        'hasExtraAssurance'   => 'extra_assurance',
        'getInsurance'        => 'insurance',
        'getLabelDescription' => 'label_description',
    ];

    $data = [
        'age_check'         => false,
        'signature'         => false,
        'only_recipient'    => false,
        'large_format'      => false,
        'return'            => false,
        'hide_sender'       => false,
        'priority_delivery' => false,
        'receipt_code'      => false,
        'same_day_delivery' => false,
        'collect'           => false,
        'insurance'         => null,
        'label_description' => null,
    ];

    foreach ($overrides as $getter => $value) {
        if (! isset($storedKeyForGetter[$getter])) {
            throw new InvalidArgumentException("No stored key is known for getter '$getter'");
        }

        $data[$storedKeyForGetter[$getter]] = $value;
    }

    return $data;
}

function shipmentOptionsFixture(array $overrides = []): ShipmentOptions
{
    return ShipmentOptions::fromCheckoutData(shipmentOptionsData($overrides));
}

/**
 * @param array<string, mixed> $overrides keyed by getter name, e.g. ['getCountry' => 'BE']
 *
 * @return array<string, mixed> keyed the way a pickup location is stored
 */
function pickupLocationData(array $overrides = []): array
{
    $storedKeyForGetter = [
        'getLocationCode'    => 'location_code',
        'getLocationName'    => 'location_name',
        'getRetailNetworkId' => 'retail_network_id',
        'getStreet'          => 'street',
        'getNumber'          => 'number',
        'getPostalCode'      => 'postal_code',
        'getCity'            => 'city',
        'getCountry'         => 'cc',
    ];

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

    foreach ($overrides as $getter => $value) {
        if (! isset($storedKeyForGetter[$getter])) {
            throw new InvalidArgumentException("No stored key is known for getter '$getter'");
        }

        $data[$storedKeyForGetter[$getter]] = $value;
    }

    return $data;
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
            'hasSignature'        => true,
            'hasOnlyRecipient'    => true,
            'getInsurance'        => 50,
            'getLabelDescription' => 'PO-12345',
        ], $shipmentOverrides)),
        'pickupLocation' => pickupLocationData(),
    ]);
}

/** Nothing stored at all: every field the response can carry is absent. */
function minimalDeliveryOptions(): DeliveryOptions
{
    return DeliveryOptions::fromCheckoutData([]);
}
