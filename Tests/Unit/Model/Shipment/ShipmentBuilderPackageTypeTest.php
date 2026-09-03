<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * packageType() consults the age check first, which overrides everything else.
 * The precedence cases below all resolve it to false, so precedence is isolated
 * from it. The last case asserts the override itself.
 *
 * The age check is now read off the resolved option set rather than recomputed
 * here — ShipmentOptionsResolver::hasAgeCheck() is its single source, and the
 * three-tier precedence it applies has its own test. That is what makes the
 * unreachable lower tiers (DR-7) reachable.
 */
function createBuilderForPackageType(int $configuredDefault): array
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn($configuredDefault);

    return [createShipmentBuilder(['defaultOptions' => $defaultOptions]), null, null];
}

function packageTypeOf($builder, array $options, array $stored, bool $ageCheck = false): int
{
    return invokePrivateMethod($builder, 'packageType', [
        DeliveryOptions::fromOrderFallback($stored),
        $options,
        ResolvedOptions::resolved([ShipmentOption::AGE_CHECK => $ageCheck]),
    ]);
}

it('lets an explicit numeric package type option win', function () {
    [$builder] = createBuilderForPackageType(PackageType::PACKAGE_SMALL);

    $result = packageTypeOf($builder, ['package_type' => PackageType::MAILBOX], ['packageType' => 'digital_stamp']);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('resolves an explicit named package type option via the name map', function () {
    [$builder] = createBuilderForPackageType(PackageType::PACKAGE_SMALL);

    $result = packageTypeOf($builder, ['package_type' => 'mailbox'], []);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('falls through to the checkout delivery option when no option is explicitly chosen', function () {
    [$builder] = createBuilderForPackageType(PackageType::PACKAGE_SMALL);

    $result = packageTypeOf($builder, [], ['packageType' => 'digital_stamp']);

    expect($result)->toBe(PackageType::DIGITAL_STAMP);
});

it('falls through to the configured default when nothing is chosen anywhere', function () {
    [$builder] = createBuilderForPackageType(PackageType::PACKAGE_SMALL);

    $result = packageTypeOf($builder, [], []);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('falls back to the configured default when the explicit option name is unmapped', function () {
    [$builder] = createBuilderForPackageType(PackageType::PACKAGE_SMALL);

    $result = packageTypeOf($builder, ['package_type' => 'not-a-real-type'], []);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('forces the package type to package when an age check applies', function () {
    // The only rule in getPackageType() that is not precedence. It lives
    // nowhere else, so it is the one most easily lost now that this method has
    // moved to ShipmentBuilder.
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE_SMALL);

    $builder = createShipmentBuilder(['defaultOptions' => $defaultOptions]);

    $result = packageTypeOf(
        $builder,
        [ShipmentOption::AGE_CHECK => true, 'package_type' => PackageType::MAILBOX],
        ['packageType' => 'digital_stamp'],
        true
    );

    expect($result)->toBe(PackageType::PACKAGE);
});
