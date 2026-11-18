<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;

/**
 * Pins LOCAL_COUNTRY_MAP. localCountryCodeFor() silently answers NL for a carrier it does not
 * know, so a carrier missing from the map ships with Dutch street-splitting rules unnoticed.
 */
it('has a local country for every carrier the module names', function () {
    foreach (array_keys(Carrier::V2_NAMES_MAP) as $carrier) {
        expect(Carrier::LOCAL_COUNTRY_MAP)->toHaveKey($carrier);
    }
});

it('answers BE for DPD and NL for every other carrier', function () {
    expect(Carrier::localCountryCodeFor(Carrier::DPD))->toBe(CountryCode::CC_BE);

    foreach (array_keys(Carrier::V2_NAMES_MAP) as $carrier) {
        if (Carrier::DPD === $carrier) {
            continue;
        }

        expect(Carrier::localCountryCodeFor($carrier))->toBe(CountryCode::CC_NL);
    }
});

it('answers NL for a carrier it does not know', function () {
    expect(Carrier::localCountryCodeFor('some-future-carrier'))->toBe(CountryCode::CC_NL)
        ->and(Carrier::localCountryCodeFor(null))->toBe(CountryCode::CC_NL);
});
