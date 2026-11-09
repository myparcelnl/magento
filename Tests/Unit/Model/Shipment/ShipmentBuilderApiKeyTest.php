<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * Both cases stop at the `empty($apiKey)` guard, before any shipment exists. The key is not
 * asserted on the shipment: Shipment has no setApiKey(), and BuiltShipment carries the key.
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
    [$builder, $track] = createConvertibleShipmentBuilder(standardCheckoutOptions(), '');

    $convert = function () use ($builder, $track) {
        $builder->build($track);
    };

    expect($convert)->toThrow(LocalizedException::class);
});

it('resolves the API key from the order\'s own store, not another store\'s', function () {
    // Only store 5 resolves to a key; the order belongs to store 9. A lookup
    // ignoring the order's store would find store 5's key and not throw.
    // This asserts *which* store is consulted, not Magento's own
    // default -> website -> store fallback.
    [$builder, $track] = createConvertibleShipmentBuilder(standardCheckoutOptions(), 'store-5-key', 9, 5);

    $convert = function () use ($builder, $track) {
        $builder->build($track);
    };

    expect($convert)->toThrow(LocalizedException::class);
});
