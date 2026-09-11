<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * Age check resolves like every other option: an explicit choice, otherwise
 * DefaultOptions::hasOptionSet(), which asks the products and then the carrier
 * setting. That shared answer is what the New Shipment page pre-checks, so the
 * form and the export agree by construction.
 *
 * There is no country gate here. Which carrier carries an age check to which
 * destination is a capabilities fact: the form offers only what the
 * account has, and the API refuses the rest with a named error.
 *
 * The product tier itself is covered in DefaultOptionsAgeCheckTest.
 */
function ageCheckFor(array $options, bool $carrierDefault): bool
{
    return createShipmentOptions('NL', CarrierPostNL::NAME, $options, $carrierDefault)->hasAgeCheck();
}

it('lets an explicit true option win', function () {
    $result = ageCheckFor([ShipmentOption::AGE_CHECK => true], false);

    expect($result)->toBeTrue();
});

it('lets an explicit false option win over the carrier default', function () {
    $result = ageCheckFor([ShipmentOption::AGE_CHECK => false], true);

    expect($result)->toBeFalse();
});

it('falls through to the product attribute, then the carrier default, when nothing is explicit', function () {
    $result = ageCheckFor([], true);

    expect($result)->toBeTrue();
});

it('reads the non-explicit tiers from DefaultOptions, so the form and the export agree', function () {
    // hasOptionSet() is what the New Shipment page pre-checks from; hasDefaultOption() is only its
    // last tier. A resolver that skips to the last tier disagrees with the form on 18+ products.
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('hasOptionSet')->with(ShipmentOption::AGE_CHECK, CarrierPostNL::NAME)->andReturn(true);
    $defaults->shouldReceive('hasDefaultOption')->andReturn(false);

    $result = createShipmentOptions('NL', CarrierPostNL::NAME, [], false, null, null, $defaults)->hasAgeCheck();

    expect($result)->toBeTrue();
});

it('keeps an explicit age check outside NL; capabilities and the API decide what a carrier carries', function () {
    $result = createShipmentOptions('BE', Carrier::UPS_STANDARD, [ShipmentOption::AGE_CHECK => true], false)->hasAgeCheck();

    expect($result)->toBeTrue();
});
