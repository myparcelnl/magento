<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/** Precedence only: the explicit option, then what the checkout stored, then package. */
function packageTypeOf(
    array $options,
    array $stored,
    bool  $ageCheck = false,
    int   $fallbackType = PackageType::PACKAGE
): int
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn($fallbackType);

    return createOrderShipmentOptions([
        'options'         => $options,
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromOrderFallback($stored),
        'resolved'        => ResolvedOptions::of([ShipmentOption::AGE_CHECK => $ageCheck]),
    ])->packageType();
}

it('lets an explicit numeric package type option win', function () {
    $result = packageTypeOf(['package_type' => PackageType::MAILBOX], ['packageType' => 'digital_stamp']);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('resolves an explicit named package type option via the name map', function () {
    $result = packageTypeOf(['package_type' => 'mailbox'], []);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('falls through to the checkout delivery option when no option is explicitly chosen', function () {
    $result = packageTypeOf([], ['packageType' => 'digital_stamp']);

    expect($result)->toBe(PackageType::DIGITAL_STAMP);
});

it('falls through to package when nothing is chosen anywhere', function () {
    $result = packageTypeOf([], []);

    expect($result)->toBe(PackageType::PACKAGE);
});

it('falls back to package when the explicit option name is unmapped and nothing is stored', function () {
    $result = packageTypeOf(['package_type' => 'not-a-real-type'], []);

    expect($result)->toBe(PackageType::PACKAGE);
});

it('keeps the stored package type when an age check applies', function () {
    // Whether a mailbox can carry an age check is the account's answer, asked at checkout.
    $result = packageTypeOf([ShipmentOption::AGE_CHECK => true], ['packageType' => 'mailbox'], true);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('leaves a pickup on the type the precedence resolved, rather than forcing a package', function () {
    $result = packageTypeOf([], ['packageType' => 'mailbox', 'isPickup' => true]);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('reads an empty delivery options column as no choice, rather than failing to parse it', function () {
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getCarrierName')->andReturn('postnl');

    $options = createOrderShipmentOptions([
        'options'        => [],
        'defaultOptions' => $defaultOptions,
        'jsonSerializer' => new Magento\Framework\Serialize\Serializer\Json(),
        'order'          => createOrder(['deliveryOptions' => '']),
    ]);

    expect(invokePrivateMethod($options, 'storedDeliveryOptions', []))->toBe(['carrier' => 'postnl']);
});
