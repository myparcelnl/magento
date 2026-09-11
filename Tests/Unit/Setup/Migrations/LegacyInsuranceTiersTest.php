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

it('keeps zero as zero', function () {
    expect(LegacyInsuranceTiers::snap([100, 250], 0))->toBe(0);
});

it('leaves an amount untouched when there are no tiers', function () {
    expect(LegacyInsuranceTiers::snap([], 137))->toBe(137);
});

it('sorts before matching, so an unsorted list gives the same answer', function () {
    expect(LegacyInsuranceTiers::snap([500, 100, 250], 137))->toBe(250);
});

/**
 * The map itself, not just snap(). Nothing exercised it, so moving the class out of
 * Model\Shipment silently broke the Carrier reference and every test still passed.
 */
it('resolves real tiers for a carrier and zone', function () {
    expect(LegacyInsuranceTiers::forCarrierAndZone(Carrier::POSTNL, LegacyInsuranceTiers::ZONE_LOCAL))
        ->toContain(100)
        ->toContain(5000)
        ->and(LegacyInsuranceTiers::forCarrierAndZone('nonexistent', LegacyInsuranceTiers::ZONE_LOCAL))
        ->toBe([]);
});
