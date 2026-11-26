<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\TrackTrace\TrackNumber;

it('tells a placeholder from a real barcode', function () {
    expect(TrackNumber::isRealBarcode('3SMYPA111'))->toBeTrue()
        ->and(TrackNumber::isRealBarcode(TrackNumber::EMPTY))->toBeFalse()
        ->and(TrackNumber::isRealBarcode(TrackNumber::PRINTED))->toBeFalse()
        ->and(TrackNumber::isRealBarcode(''))->toBeFalse()
        ->and(TrackNumber::isRealBarcode(null))->toBeFalse();
});

it('reads the barcodes of an order shipped without a shipment', function () {
    expect(TrackNumber::barcodesFromOrderRow('["3SMYPA111","3SMYPA222"]'))->toBe(['3SMYPA111', '3SMYPA222']);
});

it('leaves a placeholder out of the order row barcodes', function () {
    expect(TrackNumber::barcodesFromOrderRow('["printed","3SMYPA111"]'))->toBe(['3SMYPA111']);
});

it('reads no barcodes from a value that is no JSON list', function () {
    expect(TrackNumber::barcodesFromOrderRow(TrackNumber::PRINTED))->toBeNull()
        ->and(TrackNumber::barcodesFromOrderRow('3SMYPA111'))->toBeNull()
        ->and(TrackNumber::barcodesFromOrderRow('[not json'))->toBeNull()
        ->and(TrackNumber::barcodesFromOrderRow(null))->toBeNull();
});

it('reads back the barcodes it stores on the order row', function () {
    $stored = TrackNumber::toOrderRow([1 => '3SMYPA111', 2 => '3SMYPA222']);

    expect($stored)->toStartWith(TrackNumber::ORDER_ROW_LIST_PREFIX)
        ->and(TrackNumber::barcodesFromOrderRow($stored))->toBe(['3SMYPA111', '3SMYPA222']);
});
