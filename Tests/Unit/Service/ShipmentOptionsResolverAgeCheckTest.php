<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * Resolve age check: an explicit choice, otherwise DefaultOptions::hasOptionSet(). There is no
 * country gate here; capabilities decide which destinations get an age check.
 */
function ageCheckFor(array $options, bool $carrierDefault): bool
{
    return createShipmentOptions('NL', CarrierPostNL::NAME, $options, $carrierDefault)->resolve()->option(ShipmentOption::AGE_CHECK);
}

it('lets an explicit true option win', function () {
    $result = ageCheckFor([ShipmentOption::AGE_CHECK => true], false);

    expect($result)->toBeTrue();
});

it('lets an explicit false option win over the carrier default', function () {
    $result = ageCheckFor([ShipmentOption::AGE_CHECK => false], true);

    expect($result)->toBeFalse();
});

it('reads the non-explicit tiers from DefaultOptions, so the form and the export agree', function () {
    // hasOptionSet() is what the New Shipment page pre-checks from; hasDefaultOption() is only its
    // last tier. A resolver that skips to the last tier disagrees with the form on 18+ products.
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('hasOptionSet')->with(ShipmentOption::AGE_CHECK, CarrierPostNL::NAME)->andReturn(true);
    $defaults->shouldReceive('hasDefaultOption')->andReturn(false);

    $result = createShipmentOptions('NL', CarrierPostNL::NAME, [], false, null, null, $defaults)->resolve()->option(ShipmentOption::AGE_CHECK);

    expect($result)->toBeTrue();
});

it('keeps an explicit age check outside NL; capabilities and the API decide what a carrier carries', function () {
    $result = createShipmentOptions('BE', Carrier::UPS_STANDARD, [ShipmentOption::AGE_CHECK => true], false)->resolve()->option(ShipmentOption::AGE_CHECK);

    expect($result)->toBeTrue();
});
