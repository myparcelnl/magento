<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * Precedence only: what the order stored, then the configured default. Nothing here overrides a
 * stored type.
 *
 * One answer serves both export paths, so the pickup override the PPS path used to apply on top of
 * this is gone: a pickup keeps whatever type the precedence resolves.
 */
function packageTypeOf(
    array $stored,
    bool  $ageCheck = false,
    int   $configuredDefault = PackageType::PACKAGE_SMALL
): int
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn($configuredDefault);

    return createOrderShipmentOptions([
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromOrderFallback($stored),
        'resolved'        => ResolvedOptions::of([ShipmentOption::AGE_CHECK => $ageCheck]),
    ])->packageType();
}

it('takes the package type the order stored', function () {
    $result = packageTypeOf(['packageType' => 'digital_stamp']);

    expect($result)->toBe(PackageType::DIGITAL_STAMP);
});

it('falls through to the configured default when the order stored none', function () {
    $result = packageTypeOf([]);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('leaves the package type alone when an age check applies', function () {
    // This used to return PACKAGE, silently replacing what the customer was offered and charged
    // for. Whether a mailbox can carry an age check is the account's answer, asked at checkout
    // where a type is still being chosen; here there is only a stored type to respect.
    $result = packageTypeOf(['packageType' => 'mailbox'], true);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('leaves a pickup on the type the precedence resolved, rather than forcing a package', function () {
    // The fulfilment path used to overwrite packageType with `package` for every pickup, which
    // contradicted the checkout that offered a mailbox pickup. Both paths now answer the same.
    $result = packageTypeOf(['packageType' => 'mailbox', 'isPickup' => true]);

    expect($result)->toBe(PackageType::MAILBOX);
});
