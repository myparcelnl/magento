<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

/**
 * Compares the module value objects against the SDK adapters they replace, for every stored shape
 * the factory accepts. Delete at Phase 9 with the pin bump.
 *
 * Compared as encoded JSON, not as arrays: key order is part of the persisted format and an array
 * comparison does not see it.
 */
function sdkAndModule(array $stored): array
{
    return [
        json_encode(DeliveryOptionsAdapterFactory::create($stored)->toArray()),
        json_encode(DeliveryOptionsFactory::create($stored)->toArray()),
    ];
}

dataset('stored delivery options shapes', [
    'current checkout, pickup, camelCase nested keys' => [
        [
            'carrier'         => 'postnl',
            'date'            => '2026-09-01 12:00:00',
            'deliveryType'    => DeliveryType::PICKUP_NAME,
            'packageType'     => PackageType::PACKAGE_NAME,
            'shipmentOptions' => [
                'signature'        => true,
                'onlyRecipient'    => false,
                'insurance'        => 500,
                'labelDescription' => 'PO-1',
            ],
            'pickupLocation'  => [
                'locationName'    => 'Albert Heijn',
                'locationCode'    => '217145',
                'retailNetworkId' => 'PNPNL-01',
                'street'          => 'Pieter Cornelisz Hooftstraat',
                'number'          => '37',
                'postalCode'      => '1071BL',
                'city'            => 'Amsterdam',
                'cc'              => 'NL',
            ],
        ],
    ],
    'round trip through toArray, snake_case nested keys' => [
        [
            'carrier'         => 'postnl',
            'date'            => '2026-09-01 12:00:00',
            'deliveryType'    => DeliveryType::PICKUP_NAME,
            'packageType'     => PackageType::PACKAGE_NAME,
            'isPickup'        => true,
            'pickupLocation'  => [
                'location_name'     => 'Albert Heijn',
                'location_code'     => '217145',
                'retail_network_id' => 'PNPNL-01',
                'street'            => 'Pieter Cornelisz Hooftstraat',
                'number'            => '37',
                'postal_code'       => '1071BL',
                'city'              => 'Amsterdam',
                'cc'                => 'NL',
            ],
            'shipmentOptions' => [
                'signature'         => true,
                'collect'           => false,
                'receipt_code'      => false,
                'insurance'         => 500,
                'age_check'         => false,
                'only_recipient'    => false,
                'return'            => false,
                'same_day_delivery' => false,
                'large_format'      => false,
                'label_description' => 'PO-1',
                'hide_sender'       => false,
                'extra_assurance'   => false,
                'priority_delivery' => false,
            ],
        ],
    ],
    'current checkout, home delivery, every option set' => [
        [
            'carrier'         => 'dhlforyou',
            'date'            => '2026-09-02 09:00:00',
            'deliveryType'    => DeliveryType::MORNING_NAME,
            'packageType'     => PackageType::PACKAGE_SMALL_NAME,
            'shipmentOptions' => [
                'age_check'         => true,
                'extra_assurance'   => false,
                'hide_sender'       => true,
                'insurance'         => 250,
                'label_description' => 'ORD-9',
                'large_format'      => false,
                'only_recipient'    => true,
                'return'            => false,
                'same_day_delivery' => false,
                'signature'         => true,
                'collect'           => false,
                'receipt_code'      => false,
                'priority_delivery' => true,
            ],
        ],
    ],
    'current checkout, nothing but a delivery type' => [
        [
            'deliveryType' => DeliveryType::STANDARD_NAME,
        ],
    ],
    'legacy checkout, pickup, location under its old key' => [
        [
            'carrier'           => 'postnl',
            'date'              => '2026-09-03',
            'time'              => [['type' => DeliveryType::PICKUP]],
            'options'           => [
                'signature'         => true,
                'only_recipient'    => false,
                'insurance'         => 100,
                'priority_delivery' => true,
            ],
            'location'          => 'Primera',
            'location_code'     => '99887',
            'retail_network_id' => 'PNPNL-01',
            'street'            => 'Kalverstraat',
            'number'            => '1',
            'postal_code'       => '1012NX',
            'city'              => 'Amsterdam',
            'cc'                => 'NL',
        ],
    ],
    'legacy checkout, home delivery' => [
        [
            'carrier' => 'postnl',
            'date'    => '2026-09-04',
            'time'    => [['type' => DeliveryType::STANDARD]],
            'options' => ['insurance' => 0],
        ],
    ],
    'a package type neither side knows' => [
        [
            'deliveryType'    => DeliveryType::STANDARD_NAME,
            'packageType'     => 'pallet_xl',
            'shipmentOptions' => [],
        ],
    ],
    'a package type only the module knows' => [
        [
            'deliveryType' => DeliveryType::STANDARD_NAME,
            'packageType'  => PackageType::PALLET_NAME,
        ],
    ],
    'a delivery type only the module knows' => [
        [
            'deliveryType' => DeliveryType::EARLY_MORNING_NAME,
            'packageType'  => PackageType::PACKAGE_NAME,
        ],
    ],
]);

it('serializes exactly like the SDK adapter it replaces', function (array $stored) {
    [$sdk, $module] = sdkAndModule($stored);

    expect($module)->toBe($sdk);
})->with('stored delivery options shapes');

it('reads back what it wrote', function (array $stored) {
    $once  = DeliveryOptionsFactory::create($stored)->toArray();
    $twice = DeliveryOptionsFactory::create($once)->toArray();

    expect(json_encode($twice))->toBe(json_encode($once));
})->with('stored delivery options shapes');

/**
 * The fix this phase carries, and the reason it is more than a rename. The SDK's beta.15 name-to-id
 * map was never extended past express, so TrackTraceHolder substituted standard for these two and
 * shipped a delivery the customer did not pay for. See DR-12.
 */
it('resolves the two delivery type ids the SDK map never had', function (string $name, int $id) {
    $stored = ['deliveryType' => $name];

    expect(DeliveryOptionsAdapterFactory::create($stored)->getDeliveryTypeId())->toBeNull()
        ->and(DeliveryOptionsFactory::create($stored)->getDeliveryTypeId())->toBe($id);
})->with([
    'same day'      => [DeliveryType::SAME_DAY_NAME, DeliveryType::SAME_DAY],
    'early morning' => [DeliveryType::EARLY_MORNING_NAME, DeliveryType::EARLY_MORNING],
]);

it('resolves the two package type ids the SDK map never had', function (string $name, int $id) {
    $options = DeliveryOptionsFactory::create([
        'deliveryType' => DeliveryType::STANDARD_NAME,
        'packageType'  => $name,
    ]);

    expect(DeliveryOptionsAdapterFactory::create([
        'deliveryType' => DeliveryType::STANDARD_NAME,
        'packageType'  => $name,
    ])->getPackageTypeId())->toBeNull()
        ->and($options->packageTypeValue()->id())->toBe($id);
})->with([
    'pallet'   => [PackageType::PALLET_NAME, PackageType::PALLET],
    'envelope' => [PackageType::ENVELOPE_NAME, PackageType::ENVELOPE],
]);

it('refuses data in no recognised shape, by the class two callers catch', function () {
    DeliveryOptionsFactory::create(['carrier' => 'postnl']);
})->throws(BadMethodCallException::class);

it('names the missing key when pickup options carry no location', function () {
    DeliveryOptionsFactory::create(['deliveryType' => DeliveryType::PICKUP_NAME]);
})->throws(InvalidArgumentException::class, 'pickupLocation');

it('names the missing key when a legacy pickup is incomplete', function () {
    DeliveryOptionsFactory::create([
        'date'          => '2026-09-03',
        'time'          => [['type' => DeliveryType::PICKUP]],
        'location'      => 'Primera',
        'location_code' => '99887',
        'street'        => 'Kalverstraat',
        'number'        => '1',
        'postal_code'   => '1012NX',
        'cc'            => 'NL',
    ]);
})->throws(InvalidArgumentException::class, "'city'");

it('answers the empty SDK adapter defaults', function () {
    $defaults = DeliveryOptions::defaults();

    expect(json_encode($defaults->toArray()))
        ->toBe(json_encode((new MyParcelNL\Sdk\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter())->toArray()));
});
