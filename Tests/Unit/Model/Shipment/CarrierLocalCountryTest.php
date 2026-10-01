<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Sdk\Services\CountryCodes;

/** The local country picks the street-splitting rules of a carrier. */
it('answers BE for DPD and NL for every other carrier', function () {
    expect(Carrier::localCountryCodeFor('dpd'))->toBe(CountryCodes::CC_BE);

    foreach (legacyCarriers() as $carrier) {
        if ('dpd' !== $carrier) {
            expect(Carrier::localCountryCodeFor($carrier))->toBe(CountryCodes::CC_NL, $carrier);
        }
    }
});

it('answers NL for a carrier it does not know', function () {
    expect(Carrier::localCountryCodeFor('some-future-carrier'))->toBe(CountryCodes::CC_NL)
        ->and(Carrier::localCountryCodeFor(null))->toBe(CountryCodes::CC_NL);
});
