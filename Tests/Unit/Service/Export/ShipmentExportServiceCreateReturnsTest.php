<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use MyParcelNL\Magento\Service\Export\ShipmentExportService;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

/**
 * createReturns() against a canned API answer: which return belongs to which parent, and that the
 * mail is always asked for.
 *
 * @param array<int,array<string,mixed>> $answered the shipments the API answers with
 *
 * @return array{result: array, history: array}
 */
function createReturnsAnswering(array $answered): array
{
    $http = makeGuzzleWithHistory([
        new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'data' => ['shipments' => $answered],
        ])),
    ]);

    $service = makeExportService(['key-a' => ShipmentApiFactory::make('key-a')]);
    setPrivateProperty($service, 'returnHttpClient', $http['client']);

    $result = $service->createReturns([
        'key-a' => [['parent' => 111, 'reference_identifier' => 111, 'carrier' => 1]],
    ]);

    return ['result' => $result, 'history' => $http['history']];
}

beforeEach(function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();
});

it('maps each return shipment to the parent its reference names', function () {
    $run = createReturnsAnswering([['id' => 901, 'reference_identifier' => '111']]);

    expect($run['result'])->toBe(['returns' => ['key-a' => [901 => 111]], 'errors' => []]);
});

it('always asks the API to mail the return label', function () {
    $run = createReturnsAnswering([['id' => 901, 'reference_identifier' => '111']]);

    parse_str($run['history'][0]['request']->getUri()->getQuery(), $query);

    expect($query['send_return_mail'] ?? null)->toBe('1');
});

it('skips a return that came back without a parent reference', function () {
    mockLoggerFacade()->shouldReceive('warning')->once();

    $run = createReturnsAnswering([['id' => 901]]);

    expect($run['result'])->toBe(['returns' => [], 'errors' => []]);
});
