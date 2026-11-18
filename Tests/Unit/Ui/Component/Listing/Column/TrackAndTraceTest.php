<?php

declare(strict_types=1);

use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

// Magento\Ui is a bootstrap stub here, so the column cannot run prepareDataSource() in a unit test.
function barcodesCellFor(string $trackNumber): ?string
{
    return invokePrivateMethod(newInstanceWithoutConstructor(TrackAndTrace::class), 'barcodesFromJson', [$trackNumber]);
}

it('shows the barcodes of an order shipped without a shipment, not the stored JSON', function () {
    expect(barcodesCellFor('["3SMYPA111","3SMYPA222"]'))->toBe('3SMYPA111, 3SMYPA222');
});

it('escapes a stored barcode', function () {
    expect(barcodesCellFor('["<b>"]'))->toBe('&lt;b&gt;');
});

it('leaves a value that is no JSON list to the caller', function () {
    expect(barcodesCellFor(TrackAndTrace::VALUE_PRINTED))->toBeNull()
        ->and(barcodesCellFor('3SMYPA111'))->toBeNull()
        ->and(barcodesCellFor('[not json'))->toBeNull();
});
