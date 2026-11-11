<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\CountryCode;

it('keeps the local countries the beta.15 consignments answered', function () {
    expect(Carrier::localCountryCodeFor('dpd'))->toBe(CountryCode::CC_BE);

    foreach (legacyCarriers() as $carrier) {
        if ('dpd' !== $carrier) {
            expect(Carrier::localCountryCodeFor($carrier))->toBe(CountryCode::CC_NL, $carrier);
        }
    }
});

it('answers NL for a carrier it does not know, as the old code hardcoded', function () {
    expect(Carrier::localCountryCodeFor('some-future-carrier'))->toBe(CountryCode::CC_NL)
        ->and(Carrier::localCountryCodeFor(null))->toBe(CountryCode::CC_NL);
});
