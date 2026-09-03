<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;

/**
 * The grouping is the part worth pinning: fetchLabelPdf() needs shipment ids keyed by *resolved API
 * key value*, and the key must come from each order's own store — the same rule the export follows,
 * applied a request later. The grouping lives on ShipmentApiProvider so this controller and the
 * export collections share one implementation.
 */
function shipmentWithTracks(int $storeId, array $myParcelShipmentIds): Shipment
{
    $tracks = [];

    foreach ($myParcelShipmentIds as $shipmentId) {
        $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
        $track->shouldReceive('getData')->with('myparcel_consignment_id')->andReturn($shipmentId);
        $tracks[] = $track;
    }

    $order = Mockery::mock(Magento\Sales\Model\Order::class);
    $order->shouldReceive('getStoreId')->andReturn($storeId);

    $shipment = Mockery::mock(Shipment::class);
    $shipment->shouldReceive('getOrder')->andReturn($order);
    $shipment->shouldReceive('getAllTracks')->andReturn($tracks);

    return $shipment;
}

function groupShipmentIds(array $shipments, array $keysByStore): array
{
    $provider = new ShipmentApiProvider(createConfig([], [], $keysByStore));

    return $provider->consignmentIdsByApiKey($shipments);
}

it('groups shipment ids by the api key each order resolves', function () {
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, [111]), shipmentWithTracks(2, [222]), shipmentWithTracks(1, [333])],
        [1 => ['api/key' => 'key-a'], 2 => ['api/key' => 'key-b']]
    );

    expect($grouped)->toBe(['key-a' => [111, 333], 'key-b' => [222]]);
});

it('leaves out an order that carries no MyParcel shipment id', function () {
    // Never exported, or exported and failed. Either way there is no label to ask for.
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, [111]), shipmentWithTracks(1, [0])],
        [1 => ['api/key' => 'key-a']]
    );

    expect($grouped)->toBe(['key-a' => [111]]);
});

it('leaves out an order whose store has no api key rather than borrowing another', function () {
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, [111]), shipmentWithTracks(9, [999])],
        [1 => ['api/key' => 'key-a']]
    );

    expect($grouped)->toBe(['key-a' => [111]]);
});

