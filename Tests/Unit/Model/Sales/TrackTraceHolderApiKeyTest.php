<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * Both cases here assert the API key lookup only, and both short-circuit at
 * the `empty($apiKey)` guard (TrackTraceHolder.php:118-123) — before the
 * delivery options adapter, before ShipmentOptions, before a consignment
 * exists. Nothing carrier-specific runs, so capabilities (Phase 4) cannot
 * change the outcome.
 *
 * Deliberately not asserted: that the key ends up on the consignment. The
 * SDK's v11 Shipment has no setApiKey() at all — Phase 6 pairs each shipment
 * with its key instead — so pinning that here would fix a mechanism this
 * migration removes. "The right key reaches the right account" is proven in
 * Phase 7 against a mocked ShipmentApi, where it stays true afterwards.
 */
function standardCheckoutOptions(): array
{
    return [
        'carrier'      => CarrierPostNL::NAME,
        'deliveryType' => 'standard',
        'date'         => '2026-08-20',
    ];
}

it('raises a LocalizedException when the store has no API key', function () {
    [$holder, $track] = createConvertibleTrackTraceHolder(standardCheckoutOptions(), '');

    $convert = function () use ($holder, $track) {
        $holder->convertDataFromMagentoToApi($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0]);
    };

    expect($convert)->toThrow(LocalizedException::class);
});

it('resolves the API key from the order\'s own store, not another store\'s', function () {
    // Only store 5 resolves to a key; the order belongs to store 9. A lookup
    // that ignored the order's store would find store 5's key and not throw.
    //
    // Scope of this assertion: *which* store the lookup consults. Magento
    // resolves the path itself through a default -> website -> store fallback
    // (Store\Model\Config\Processor\Fallback), which is core behaviour, not
    // ours. What follows from it — two stores inheriting one default key
    // grouping into a single API call, a website-level override splitting
    // them — is observable in Phase 7's export orchestration (TR-000006) and
    // belongs there.
    [$holder, $track] = createConvertibleTrackTraceHolder(standardCheckoutOptions(), 'store-5-key', 9, 5);

    $convert = function () use ($holder, $track) {
        $holder->convertDataFromMagentoToApi($track, ['carrier' => CarrierPostNL::NAME, 'insurance' => 0]);
    };

    expect($convert)->toThrow(LocalizedException::class);
});
