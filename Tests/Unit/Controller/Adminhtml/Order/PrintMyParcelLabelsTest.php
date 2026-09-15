<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;

/**
 * The grouping is the part worth pinning: fetchLabelPdf() needs shipment ids keyed by *resolved API
 * key value*, and the key must come from each order's own store — the same rule the export follows,
 * applied a request later. The grouping lives on ShipmentApiProvider so the label controller and the
 * export collections share one implementation.
 *
 * The tracks are handed in rather than read off the shipment: a Shipment caches its own tracks and
 * would answer with the tracks as they were before this run wrote its shipment ids.
 *
 * @return array{0: Shipment, 1: array<int,Magento\Sales\Model\Order\Shipment\Track>}
 */
function shipmentWithTracks(int $entityId, int $storeId, array $myParcelShipmentIds): array
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

    $provider = new ShipmentApiProvider(createConfig([], [], $keysByStore), createUserAgent());

    return $provider->consignmentIdsByApiKey($shipments, $tracksByShipmentId);
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


it('answers both request methods the label download uses', function () {
    // label-download.js GETs the grid row's ready-made href and POSTs an export's shipment id list,
    // which is too long for a URL. Drop either and HttpMethodValidator answers that half with a 404
    // it logs at debug level only, so the admin sees "could not be downloaded" and the log nothing.
    expect(class_implements(MyParcelNL\Magento\Controller\Adminhtml\Order\PrintMyParcelLabels::class))
        ->toContain(Magento\Framework\App\Action\HttpGetActionInterface::class)
        ->toContain(Magento\Framework\App\Action\HttpPostActionInterface::class);
});
