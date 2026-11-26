<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;

/**
 * A shipment and its tracks. fetchLabelPdf() needs shipment ids keyed by resolved API key value,
 * and the key must come from each order's own store, as in the export.
 *
 * @return array{0: Shipment, 1: array<int,Magento\Sales\Model\Order\Shipment\Track>}
 */
function shipmentWithTracks(int $entityId, int $storeId, array $myParcelShipmentIds): array
{
    $tracks = [];

    foreach ($myParcelShipmentIds as $shipmentId) {
        $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
        $track->shouldReceive('getData')->with('myparcel_shipment_id')->andReturn($shipmentId);
        $tracks[] = $track;
    }

    $order = Mockery::mock(Magento\Sales\Model\Order::class);
    $order->shouldReceive('getStoreId')->andReturn($storeId);

    $shipment = Mockery::mock(Shipment::class);
    $shipment->shouldReceive('getId')->andReturn($entityId);
    $shipment->shouldReceive('getOrder')->andReturn($order);
    $shipment->shouldReceive('getStoreId')->andReturn($storeId);

    return [$shipment, $tracks];
}

/** @param array<int,array{0: Shipment, 1: array}> $entries from shipmentWithTracks() */
function groupShipmentIds(array $entries, array $keysByStore): array
{
    $shipments          = [];
    $tracksByShipmentId = [];

    foreach ($entries as [$shipment, $tracks]) {
        $shipments[]                                   = $shipment;
        $tracksByShipmentId[(int) $shipment->getId()] = $tracks;
    }

    $collection = partialOrderCollection($shipments);
    setPrivateProperty($collection, 'apiProvider', new ShipmentApiProvider(createConfig([], [], $keysByStore), createUserAgent()));

    return $collection->getMyParcelShipmentIdsByApiKey($tracksByShipmentId);
}

it('groups shipment ids by the api key each order resolves', function () {
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, 1, [111]), shipmentWithTracks(2, 2, [222]), shipmentWithTracks(3, 1, [333])],
        [1 => ['api/key' => 'key-a'], 2 => ['api/key' => 'key-b']]
    );

    expect($grouped)->toBe(['key-a' => [111, 333], 'key-b' => [222]]);
});

it('leaves out an order that carries no MyParcel shipment id', function () {
    // Never exported, or exported and failed. Either way there is no label to ask for.
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, 1, [111]), shipmentWithTracks(2, 1, [0])],
        [1 => ['api/key' => 'key-a']]
    );

    expect($grouped)->toBe(['key-a' => [111]]);
});

it('leaves out an order whose store has no api key rather than borrowing another', function () {
    $grouped = groupShipmentIds(
        [shipmentWithTracks(1, 1, [111]), shipmentWithTracks(2, 9, [999])],
        [1 => ['api/key' => 'key-a']]
    );

    expect($grouped)->toBe(['key-a' => [111]]);
});
