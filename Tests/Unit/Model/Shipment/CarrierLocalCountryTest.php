<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;

/**
 * Pins LOCAL_COUNTRY_MAP now that the beta.15 consignments it was sourced from are gone.
 * localCountryCodeFor() silently answers NL for a carrier it does not know, so a carrier missing
 * from the map would ship with Dutch street-splitting rules without anyone noticing.
 */
it('has a local country for every carrier the module names', function () {
    foreach (array_keys(Carrier::V2_NAMES_MAP) as $carrier) {
        expect(Carrier::LOCAL_COUNTRY_MAP)->toHaveKey($carrier);
    }
});

it('keeps the local countries the beta.15 consignments answered', function () {
    // DPD ships from BE; every other carrier from NL. Sourced from getLocalCountryCode() at beta.15.
    expect(Carrier::localCountryCodeFor(Carrier::DPD))->toBe(CountryCode::CC_BE);

    foreach (array_keys(Carrier::V2_NAMES_MAP) as $carrier) {
        if (Carrier::DPD === $carrier) {
            continue;
        }

        expect(Carrier::localCountryCodeFor($carrier))->toBe(CountryCode::CC_NL);
    }
});

it('answers NL for a carrier it does not know, as the old code hardcoded', function () {
    expect(Carrier::localCountryCodeFor('some-future-carrier'))->toBe(CountryCode::CC_NL)
        ->and(Carrier::localCountryCodeFor(null))->toBe(CountryCode::CC_NL);
});
