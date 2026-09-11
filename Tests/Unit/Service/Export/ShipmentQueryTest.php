<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ShipmentQuery;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesShipments;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesShipmentsData;

/**
 * The reason this class exists is the second argument: the SDK's own query service hard-codes it to
 * null, so without it the consumer portal link never arrives and the module would have to guess.
 */
function shipmentQueryFor(?ShipmentResponsesShipments $response, array &$calls): ShipmentQuery
{
    $api = Mockery::mock(ShipmentApi::class);
    $api->shouldReceive('getShipmentsById')->andReturnUsing(
        function (...$args) use ($response, &$calls) {
            $calls[] = $args;

            return $response ?? new ShipmentResponsesShipments();
        }
    );

    return (new ShipmentQuery($api))->setUserAgents(createUserAgent()->map());
}

function shipmentsResponse(array $shipments): ShipmentResponsesShipments
{
    return (new ShipmentResponsesShipments())
        ->setData((new ShipmentResponsesShipmentsData())->setShipments($shipments));
}

it('asks for the consumer portal link', function () {
    $calls = [];

    shipmentQueryFor(shipmentsResponse([]), $calls)->findMany([11, 12]);

    expect($calls)->toHaveCount(1)
        ->and($calls[0][0])->toBe('11;12')
        ->and($calls[0][1])->toBeTrue();
});

it('sends the module user agent the SDK trait assembled', function () {
    // The third argument is getUserAgentHeader(): our map, plus the SDK version and PHP that the
    // trait appends. Nothing asserted this before, which is how the platform key was lost once.
    $calls = [];

    shipmentQueryFor(shipmentsResponse([]), $calls)->findMany([11]);

    expect($calls[0][2])->toContain('Magento2/2.4.6')
        ->and($calls[0][2])->toContain('MyParcel-Magento2/5.9.0')
        ->and($calls[0][2])->toContain('php/');
});

it('keys the shipments by their id', function () {
    $calls = [];
    $response = shipmentsResponse([
        (new ShipmentDefsShipment())->setId(12)->setBarcode('3STBJG2'),
        (new ShipmentDefsShipment())->setId(11)->setBarcode('3STBJG1'),
    ]);

    $shipments = shipmentQueryFor($response, $calls)->findMany([11, 12]);

    expect(array_keys($shipments))->toBe([12, 11])
        ->and($shipments[11]->getBarcode())->toBe('3STBJG1');
});

it('answers an empty array when the response carries no data', function () {
    $calls = [];

    expect(shipmentQueryFor(new ShipmentResponsesShipments(), $calls)->findMany([11]))->toBe([]);
});

it('makes no call at all without ids', function () {
    $calls = [];

    expect(shipmentQueryFor(shipmentsResponse([]), $calls)->findMany([]))->toBe([])
        ->and($calls)->toBeEmpty();
});
