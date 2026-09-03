<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * The age check moved off the builder: ShipmentOptionsResolver::hasAgeCheck()
 * is its single source, and the builder reads the resolved option set.
 *
 * That is what fixes DR-7. TrackTraceHolder::getAgeCheck() passed the Track
 * itself, not its items, into getAgeCheckFromProduct(), which foreaches it — a
 * Track is not iterable, so the loop never ran and it returned false rather
 * than null. Since `??` only falls through on null, the product-attribute and
 * carrier-default tiers below were both unreachable. The resolver passes
 * $order->getItems(), so all three tiers work and the ->todo() is gone.
 *
 * Not covered here: the NL-only country gate. Its expected value depends on
 * which country, so per DR-5 it is a carrier fact Phase 4 tests against a
 * stubbed capabilities response. Every case below is NL, and asserts only the
 * precedence between option sources.
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
