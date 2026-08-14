<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Helper\ShipmentOptions;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

function createHolderForAgeCheck(bool $carrierDefault): array
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasDefaultOption')->andReturn($carrierDefault);

    $holder = createTrackTraceHolder([
        'defaultOptions' => $defaultOptions,
        'carrier'        => CarrierPostNL::NAME,
    ]);

    // Never actually iterated by the buggy call this method makes — see the
    // ->todo() case below (DR-7) — so a bare mock with no expectations is enough.
    return [$holder, Mockery::mock(Track::class)];
}

// Not covered here: the NL-only country gate at the top of getAgeCheck().
// Its expected value depends on which country, so per DR-5 it is a carrier
// fact this migration makes account-specific — Phase 4 tests it against a
// stubbed capabilities response. Every case below is NL, and asserts only
// the precedence between option sources.

it('lets an explicit true option win', function () {
    [$holder, $track] = createHolderForAgeCheck(false);
    $address = createAddress(['getCountryId' => 'NL']);

    $result = invokePrivateMethod($holder, 'getAgeCheck', [$track, $address, [ShipmentOptions::AGE_CHECK => true]]);

    expect($result)->toBeTrue();
});

it('lets an explicit false option win over the carrier default', function () {
    [$holder, $track] = createHolderForAgeCheck(true);
    $address = createAddress(['getCountryId' => 'NL']);

    $result = invokePrivateMethod($holder, 'getAgeCheck', [$track, $address, [ShipmentOptions::AGE_CHECK => false]]);

    expect($result)->toBeFalse();
});

it('falls through to the product attribute, then the carrier default, when nothing is explicit', function () {
    // DR-7 (docs/design/sdk-v11-migration.md): getAgeCheck() passes
    // $magentoTrack itself — not its items — into
    // ShipmentOptions::getAgeCheckFromProduct(), which does `foreach
    // ($products as $product)`. Track has no Iterator/IteratorAggregate
    // anywhere in its hierarchy, so that loop runs zero times and the
    // function always returns false, never null. Because `??` only falls
    // through on null, the carrier-default tier below can never be reached
    // either. Only the explicit-option tier (tested above) is reachable
    // today. Fixed alongside the customs double-add in Phase 6, when
    // TrackTraceHolder is rewritten into ShipmentBuilder.
    [$holder, $track] = createHolderForAgeCheck(true);
    $address = createAddress(['getCountryId' => 'NL']);

    $result = invokePrivateMethod($holder, 'getAgeCheck', [$track, $address, []]);

    expect($result)->toBeTrue();
})->todo();
