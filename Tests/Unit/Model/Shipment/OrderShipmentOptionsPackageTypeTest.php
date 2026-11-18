<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * Precedence only: the explicit option, then what the checkout stored, then the configured
 * default. Nothing here overrides a stored type, also not for a pickup.
 */
function packageTypeOf(
    array $options,
    array $stored,
    bool  $ageCheck = false,
    int   $configuredDefault = PackageType::PACKAGE_SMALL
): int
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn($configuredDefault);

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

it('falls through to the configured default when nothing is chosen anywhere', function () {
    $result = packageTypeOf([], []);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('falls back to the configured default when the explicit option name is unmapped', function () {
    $result = packageTypeOf(['package_type' => 'not-a-real-type'], []);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
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
