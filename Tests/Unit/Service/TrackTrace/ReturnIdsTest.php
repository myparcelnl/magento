<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Block\Sales\View;
use MyParcelNL\Magento\Service\TrackTrace\ReturnIds;

function trackWithReturnIds(?string $stored): DataObject
{
    return new DataObject([ReturnIds::FIELD => $stored]);
}

it('reads the return shipment ids a track holds', function (?string $stored, array $expected) {
    expect(ReturnIds::of(trackWithReturnIds($stored)))->toBe($expected);
})->with([
    'never mailed' => [null, []],
    'empty'        => ['', []],
    'one'          => ['[901]', [901]],
    'several'      => ['[901,902]', [901, 902]],
    'malformed'    => ['not json', []],
    'not a list'   => ['901', []],
]);

it('lists every return mailed for an order, over all its tracks', function () {
    $order = Mockery::mock(Magento\Sales\Model\Order::class);
    $order->shouldReceive('getTracksCollection')->andReturn([
        trackWithReturnIds('[901]'),
        trackWithReturnIds(null),
        trackWithReturnIds('[902,903]'),
    ]);

    $block = newInstanceWithoutConstructor(View::class);

    expect($block->getReturnShipmentIds($order))->toBe([901, 902, 903]);
});
