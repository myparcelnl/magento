<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\LegacyInsuranceTiers;

/**
 * The frozen half of this asserts against the installed beta.15 SDK, so it goes when the pin moves
 * in Phase 9 — like ConstantEquivalenceTest. The rule half stays: UpgradeData depends on it.
 */
function zoneCountryCodes(): array
{
    return [
        LegacyInsuranceTiers::ZONE_LOCAL => null,
        LegacyInsuranceTiers::ZONE_BE    => CountryCodes::CC_BE,
        LegacyInsuranceTiers::ZONE_EU    => CountryCodes::ZONE_EU,
        LegacyInsuranceTiers::ZONE_ROW   => CountryCodes::ZONE_ROW,
    ];
}

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

