<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/**
 * The key comes from the order's own store and travels on the BuiltShipment: Shipment has no
 * setApiKey(). The export service groups and sends by that key.
 */
function standardCheckoutOptions(): array
{
    return [
        'carrier'      => CarrierPostNL::NAME,
        'deliveryType' => 'standard',
        'date'         => '2026-08-20',
    ];
}

it('carries the API key of the order\'s own store', function (int $storeId, string $apiKey) {
    // The key is set on this store only, so neither the default key nor another store's key can pass.
    [$builder, $track] = createConvertibleShipmentBuilder(standardCheckoutOptions(), $apiKey, $storeId, $storeId);

    expect($builder->build($track)->getApiKey())->toBe($apiKey);
})->with([
    'store 5' => [5, 'store-5-key'],
    'store 9' => [9, 'store-9-key'],
]);

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
