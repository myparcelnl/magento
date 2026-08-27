<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\LegacyInsuranceTiers;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Services\CountryCodes;

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

it('holds exactly what the pinned SDK holds, for every configured carrier and zone', function () {
    foreach (array_keys(Config::CARRIERS_XML_PATH_MAP) as $carrierName) {
        $consignment = ConsignmentFactory::createByCarrierName($carrierName);

        foreach (zoneCountryCodes() as $zone => $countryCode) {
            $sdk    = $consignment->getInsurancePossibilities($countryCode);
            $frozen = LegacyInsuranceTiers::forCarrierAndZone($carrierName, $zone);

            sort($sdk);

            expect($frozen)->toBe($sdk, sprintf('%s / %s', $carrierName, $zone));
        }
    }
});

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

it('answers zero for export where the carrier offered no insurance at all', function () {
    expect(LegacyInsuranceTiers::acceptableForSdk(Carrier::TRUNKRS, LegacyInsuranceTiers::ZONE_LOCAL, 137))
        ->toBe(0)
        ->and(LegacyInsuranceTiers::acceptableForSdk(Carrier::GLS, LegacyInsuranceTiers::ZONE_BE, 137))
        ->toBe(0);
});

it('answers a tier for export where the carrier offered insurance', function () {
    expect(LegacyInsuranceTiers::acceptableForSdk(Carrier::POSTNL, LegacyInsuranceTiers::ZONE_LOCAL, 137))
        ->toBe(250)
        ->and(LegacyInsuranceTiers::acceptableForSdk(Carrier::POSTNL, LegacyInsuranceTiers::ZONE_EU, 137))
        ->toBe(500);
});

it('maps a destination country to the zone beta.15 would have used', function () {
    expect(LegacyInsuranceTiers::zoneFor(null))->toBe(LegacyInsuranceTiers::ZONE_LOCAL)
        ->and(LegacyInsuranceTiers::zoneFor('NL'))->toBe(LegacyInsuranceTiers::ZONE_LOCAL)
        ->and(LegacyInsuranceTiers::zoneFor('BE'))->toBe(LegacyInsuranceTiers::ZONE_BE)
        ->and(LegacyInsuranceTiers::zoneFor('DE'))->toBe(LegacyInsuranceTiers::ZONE_EU)
        ->and(LegacyInsuranceTiers::zoneFor('US'))->toBe(LegacyInsuranceTiers::ZONE_ROW);
});
