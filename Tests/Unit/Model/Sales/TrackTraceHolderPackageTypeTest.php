<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * TrackTraceHolder::getPackageType() calls getAgeCheck() first, and a
 * forced-package-type result there would override everything below — but
 * getAgeCheck() returns false immediately for a non-NL address, before it
 * touches anything else. Using a non-NL address in every case here isolates
 * package-type precedence from age-check precedence (which is carrier-fact
 * territory the SDK v11 migration plan defers to Phase 4 — see DR-5).
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
