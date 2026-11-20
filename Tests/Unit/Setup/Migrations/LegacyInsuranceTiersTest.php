<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Setup\Migrations\LegacyInsuranceTiers;

/**
 * The rules UpgradeData depends on: snapping a stored amount up to a tier, and which frozen list a
 * carrier and zone map to. The tier values themselves are constants and need no witness.
 */
it('rounds an amount up to the nearest tier', function () {
    $tiers = [100, 250, 500];

    expect(LegacyInsuranceTiers::snap($tiers, 1))->toBe(100)
        ->and(LegacyInsuranceTiers::snap($tiers, 100))->toBe(100)
        ->and(LegacyInsuranceTiers::snap($tiers, 137))->toBe(250)
        ->and(LegacyInsuranceTiers::snap($tiers, 500))->toBe(500);
});

it('rounds an amount above the largest tier down to it', function () {
    expect(LegacyInsuranceTiers::snap([100, 250, 500], 9000))->toBe(500);
});

it('reads a stored custom amount as an int, as the database answers a string', function () {
    $rows = [
        ['path' => 'myparcelnl_magento_postnl_settings/default_options/insurance_custom_amount', 'value' => '300'],
    ];

    expect(LegacyInsuranceTiers::customAmount($rows, 'myparcelnl_magento_postnl_settings/default_options/insurance_custom_amount'))->toBe(300)
        ->and(LegacyInsuranceTiers::customAmount($rows, 'other/path'))->toBe(0);
});

it('keeps zero as zero', function () {
    expect(LegacyInsuranceTiers::snap([100, 250], 0))->toBe(0);
});

it('leaves an amount untouched when there are no tiers', function () {
    expect(LegacyInsuranceTiers::snap([], 137))->toBe(137);
});

it('sorts before matching, so an unsorted list gives the same answer', function () {
    expect(LegacyInsuranceTiers::snap([500, 100, 250], 137))->toBe(250);
});

/** Covers the map itself, not only snap(). */
it('resolves real tiers for a carrier and zone', function () {
    expect(LegacyInsuranceTiers::forCarrierAndZone('postnl', LegacyInsuranceTiers::ZONE_LOCAL))
        ->toContain(100)
        ->toContain(5000)
        ->and(LegacyInsuranceTiers::forCarrierAndZone('nonexistent', LegacyInsuranceTiers::ZONE_LOCAL))
        ->toBe([]);
});
