<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\SecondaryShipmentResource;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;

/**
 * updateMagentoTrack() is what puts the consumer portal link on the track, and nothing covered it —
 * which is why PPS orders went without one unnoticed. There is no PPS-specific link path:
 * once a track carries myparcel_consignment_id it refreshes through here like any other.
 *
 * @param array<int,ShipmentDefsShipment> $latest keyed by MyParcel shipment id
 *
 * @return array{0: MagentoOrderCollection, 1: object} the collection and a recorder of what was set
 */
function collectionRefreshing(int $consignmentId, array $latest): array
{
    $saved = new class {
        public array $numbers = [];
        public array $data    = [];
        public int   $saves   = 0;
        /** @var array<int,array{number: string|null, data: array}> one per track this run created */
        public array $created = [];
    };

    $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
    $track->shouldReceive('getData')->with('myparcel_consignment_id')->andReturn($consignmentId);
    $track->shouldReceive('setTrackNumber')->andReturnUsing(static function ($number) use ($track, $saved) {
        $saved->numbers[] = $number;

        return $track;
    });
    $track->shouldReceive('setData')->andReturnUsing(static function ($key, $value) use ($track, $saved) {
        $saved->data[$key] = $value;

        return $track;
    });
    $track->shouldReceive('save')->andReturnUsing(static function () use ($track, $saved) {
        $saved->saves++;

        return $track;
    });

    $shipment  = createShipment();
    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturn(new ArrayIterator([$shipment]));

    $trackCollection = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class);
    $trackCollection->shouldReceive('getItems')->andReturn([$track]);

    $exportService = Mockery::mock(MyParcelNL\Magento\Service\Export\ShipmentExportService::class);
    $exportService->shouldReceive('fetchLatest')->andReturn($latest);

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);
    $collection->shouldReceive('getTrackByShipment')->andReturn($trackCollection);
    $collection->shouldReceive('getMyparcelConsignmentIdsByApiKey')->andReturn(['key' => [$consignmentId]]);
    $collection->shouldReceive('updateOrderGrid')->andReturnSelf();
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(static function () use ($saved) {
        $made = ['number' => null, 'data' => []];
        $key  = array_push($saved->created, $made) - 1;

        $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
        $track->shouldReceive('setTrackNumber')->andReturnUsing(
            static function ($number) use ($track, $saved, $key) {
                $saved->created[$key]['number'] = $number;

                return $track;
            }
        );
        $track->shouldReceive('setData')->andReturnUsing(
            static function ($field, $value) use ($track, $saved, $key) {
                $saved->created[$key]['data'][$field] = $value;

                return $track;
            }
        );
        $track->shouldReceive('save')->andReturnSelf();

        return $track;
    });
    setPrivateProperty($collection, 'exportService', $exportService);

    return [$collection, $saved];
}

function apiShipment(int $id, ?string $barcode, ?string $link, ?int $status = 3): ShipmentDefsShipment
{
    $shipment = (new ShipmentDefsShipment())->setId($id);

    if (null !== $barcode) {
        $shipment->setBarcode($barcode);
    }

    if (null !== $link) {
        $shipment->setLinkConsumerPortal($link);
    }

    if (null !== $status) {
        $shipment->setStatus($status);
    }

    return $shipment;
}


/**
 * One collo of a multicollo, as the shipment API returns it: a full shipment in its own right, with
 * its own id, barcode, status and link. This is what the module never read.
 */
function apiCollo(int $id, string $barcode, ?string $link = null, ?int $status = 3): SecondaryShipmentResource
{
    $collo = (new SecondaryShipmentResource())->setId($id)->setBarcode($barcode);

    if (null !== $link) {
        $collo->setLinkConsumerPortal($link);
    }

    if (null !== $status) {
        $collo->setStatus($status);
    }

    return $collo;
}

/** @param SecondaryShipmentResource[] $colli */
function apiShipmentWithColli(int $id, string $barcode, array $colli): ShipmentDefsShipment
{
    return apiShipment($id, $barcode, null)->setSecondaryShipments($colli);
}
