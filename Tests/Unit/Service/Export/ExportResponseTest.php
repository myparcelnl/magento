<?php

declare(strict_types=1);

use Magento\Framework\Message\MessageInterface;
use MyParcelNL\Magento\Service\Export\ExportResponse;

/**
 * The positions are the part worth pinning: the SDK reads the paper size from that value's *type*,
 * so an empty positions parameter would silently turn every A6 print into A4.
 */
function exportResponseWith(array $messages = []): ExportResponse
{
    $response = newInstanceWithoutConstructor(ExportResponse::class);

    $url = Mockery::mock(Magento\Framework\UrlInterface::class);
    $url->shouldReceive('getUrl')->andReturnUsing(static function (string $route, array $params = []) {
        return $route . '?' . http_build_query($params);
    });

    $collection = Mockery::mock(Magento\Framework\Message\Collection::class);
    $collection->shouldReceive('getItems')->andReturn($messages);

    $manager = Mockery::mock(Magento\Framework\Message\ManagerInterface::class);
    $manager->shouldReceive('getMessages')->with(true)->andReturn($collection);

    setPrivateProperty($response, 'url', $url);
    setPrivateProperty($response, 'messageManager', $manager);
    setPrivateProperty($response, 'labelPositions', makeLabelPositions());

    return $response;
}

function message(string $type, string $text): MessageInterface
{
    $message = Mockery::mock(MessageInterface::class);
    $message->shouldReceive('getType')->andReturn($type);
    $message->shouldReceive('getText')->andReturn($text);

    return $message;
}

it('has nothing to fetch when the export produced no shipments', function () {
    expect(exportResponseWith()->labels([], 'download', [1, 2]))->toBeNull();
});

it('sends the chosen A4 positions as one comma string', function () {
    $labels = exportResponseWith()->labels([7, 9], 'download', [2, 4]);

    expect($labels['url'])->toContain('positions=2%2C4')
        ->and($labels['url'])->toContain('shipment_ids=7%2C9')
        ->and($labels['url'])->toContain('request_type=download');
});

it('asks the label request to notify only when told to', function () {
    // Track & trace emails travel with the label request — that is the first moment a barcode
    // exists — but only the order grid's flow sends them, so the flag is explicit.
    expect(exportResponseWith()->labels([7], 'download', null, true)['url'])->toContain('notify=1')
        ->and(exportResponseWith()->labels([7], 'download', null)['url'])->not->toContain('notify');
});

it('leaves the positions parameter off entirely rather than sending it empty', function ($positions) {
    expect(exportResponseWith()->labels([7], 'download', $positions)['url'])
        ->not->toContain('positions');
})->with([
    'A6 sends null'      => [null],
    'no chosen position' => [[]],
    'a bare number'      => [1],
    'an empty string'    => [''],
]);

it('hands the messages over as type and text', function () {
    $response = exportResponseWith([
        message('error', 'Order 100 has a bad postal code'),
        message('warning', 'Order 101 was already exported'),
    ]);

    expect(invokePrivateMethod($response, 'messages'))->toBe([
        ['type' => 'error', 'text' => 'Order 100 has a bad postal code'],
        ['type' => 'warning', 'text' => 'Order 101 was already exported'],
    ]);
});
