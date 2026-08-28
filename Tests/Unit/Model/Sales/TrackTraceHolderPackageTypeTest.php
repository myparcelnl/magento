<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * getPackageType() consults getAgeCheck() first, which overrides everything
 * else. The precedence cases below all use a non-NL address, where getAgeCheck()
 * returns false immediately, so precedence is isolated from it. The last case
 * asserts the override itself.
 */
function createHolderForPackageType(int $configuredDefault): array
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn($configuredDefault);

    $holder  = createTrackTraceHolder(['defaultOptions' => $defaultOptions]);
    $address = createAddress(['getCountryId' => 'DE']);
    $track   = Mockery::mock(Track::class);

    return [$holder, $track, $address];
}

it('lets an explicit numeric package type option win', function () {
    [$holder, $track, $address] = createHolderForPackageType(PackageType::PACKAGE_SMALL);

    $result = invokePrivateMethod($holder, 'getPackageType', [
        $track,
        $address,
        ['package_type' => PackageType::MAILBOX],
        ['packageType' => 'digital_stamp'],
    ]);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('resolves an explicit named package type option via the name map', function () {
    [$holder, $track, $address] = createHolderForPackageType(PackageType::PACKAGE_SMALL);

    $result = invokePrivateMethod($holder, 'getPackageType', [
        $track,
        $address,
        ['package_type' => 'mailbox'],
        [],
    ]);

    expect($result)->toBe(PackageType::MAILBOX);
});

it('falls through to the checkout delivery option when no option is explicitly chosen', function () {
    [$holder, $track, $address] = createHolderForPackageType(PackageType::PACKAGE_SMALL);

    $result = invokePrivateMethod($holder, 'getPackageType', [
        $track,
        $address,
        [],
        ['packageType' => 'digital_stamp'],
    ]);

    expect($result)->toBe(PackageType::DIGITAL_STAMP);
});

it('falls through to the configured default when nothing is chosen anywhere', function () {
    [$holder, $track, $address] = createHolderForPackageType(PackageType::PACKAGE_SMALL);

    $result = invokePrivateMethod($holder, 'getPackageType', [$track, $address, [], []]);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('falls back to the configured default when the explicit option name is unmapped', function () {
    [$holder, $track, $address] = createHolderForPackageType(PackageType::PACKAGE_SMALL);

    $result = invokePrivateMethod($holder, 'getPackageType', [
        $track,
        $address,
        ['package_type' => 'not-a-real-type'],
        [],
    ]);

    expect($result)->toBe(PackageType::PACKAGE_SMALL);
});

it('forces the package type to package when an age check applies', function () {
    // The only rule in getPackageType() that is not precedence. It lives
    // nowhere else — ShipmentOptionsResolver does not carry it — so it is the
    // one most easily lost when this method moves to ShipmentBuilder.
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE_SMALL);
    $defaultOptions->shouldReceive('hasDefaultOption')->andReturn(false);

    $holder  = createTrackTraceHolder([
        'defaultOptions' => $defaultOptions,
        'carrier'        => CarrierPostNL::NAME,
    ]);
    $address = createAddress(['getCountryId' => 'NL']);
    $track   = Mockery::mock(Track::class);

    $result = invokePrivateMethod($holder, 'getPackageType', [
        $track,
        $address,
        [ShipmentOption::AGE_CHECK => true, 'package_type' => PackageType::MAILBOX],
        ['packageType' => 'digital_stamp'],
    ]);

    expect($result)->toBe(PackageType::PACKAGE);
});
