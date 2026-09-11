<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\SecondaryShipmentResource;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment;

/**
 * A partial MagentoOrderCollection over shipments that actually iterate.
 *
 * IteratorAggregate is named explicitly: the bootstrap stubs uninstalled Magento classes as empty
 * ones, so a mock of the collection alone would iterate nothing.
 *
 * @param \Magento\Sales\Model\Order\Shipment[] $shipmentList
 *
 * @return MagentoOrderCollection|Mockery\MockInterface
 */
function partialOrderCollection(array $shipmentList): MagentoOrderCollection
{
    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturnUsing(
        static function () use ($shipmentList) {
            return new ArrayIterator($shipmentList);
        }
    );

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);

    return $collection;
}

/**
 * updateMagentoTrack() is what puts the consumer portal link on the track, and nothing covered it —
 * which is why PPS orders went without one unnoticed. There is no PPS-specific link path:
 * once a track carries myparcel_consignment_id it refreshes through here like any other.
 *
 * @param array<int,ShipmentDefsShipment> $latest keyed by MyParcel shipment id
 *
 * @return array{0: MagentoOrderCollection, 1: object} the collection and a recorder of what was set
 */
function collectionRefreshing(int $consignmentId, array $latest, bool $changed = true): array
{
    $saved = new class {
        public array $numbers = [];
        public array $data    = [];
        public int   $saves   = 0;
        /** @var array<int,array{number: string|null, data: array}> one per track this run created */
        public array $created = [];
    };

    $track = recordingTrack(
        [
            'setTrackNumber' => static function ($number) use ($saved): void {
                $saved->numbers[] = $number;
            },
            'setData'        => static function ($key, $value) use ($saved): void {
                $saved->data[$key] = $value;
            },
            'save'           => static function () use ($saved): void {
                $saved->saves++;
            },
        ],
        [
            'getData'        => static function (?string $key = null) use ($consignmentId) {
                return 'myparcel_consignment_id' === $key ? $consignmentId : null;
            },
            // False models a refresh that brought nothing new; the caller then skips the save.
            'hasDataChanges' => $changed,
        ]
    );

    $shipment = createShipment();

    $exportService = Mockery::mock(MyParcelNL\Magento\Service\Export\ShipmentExportService::class);
    $exportService->shouldReceive('fetchLatest')->andReturn($latest);

    $collection = partialOrderCollection([$shipment]);
    $collection->shouldReceive('tracksByShipmentId')->andReturn([(int) $shipment->getId() => [$track]]);
    $collection->shouldReceive('getMyparcelConsignmentIdsByApiKey')->andReturn(['key' => [$consignmentId]]);
    $collection->shouldReceive('updateOrderGrid')->andReturnSelf();
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(static function () use ($saved) {
        $made = ['number' => null, 'data' => []];
        $key  = array_push($saved->created, $made) - 1;

        return recordingTrack([
            'setTrackNumber' => static function ($number) use ($saved, $key): void {
                $saved->created[$key]['number'] = $number;
            },
            'setData'        => static function ($field, $value) use ($saved, $key): void {
                $saved->created[$key]['data'][$field] = $value;
            },
        ]);
    });
    setPrivateProperty($collection, 'exportService', $exportService);

    return [$collection, $saved];
}

/**
 * A collection whose one Magento shipment already carries several track rows.
 *
 * This is the multicollo shape: persist() parks every collo on the parent's shipment id, so the
 * rows beyond the first are spares waiting for an id of their own. collectionRefreshing() models
 * one row and cannot express it.
 *
 * @param int[]                           $consignmentIds one per existing row, in order
 * @param array<int,ShipmentDefsShipment> $latest         keyed by MyParcel shipment id
 *
 * @return array{0: MagentoOrderCollection, 1: object} the collection, and a recorder holding one
 *         entry per pre-existing row plus one per row this run created
 */
function collectionRefreshingTracks(array $consignmentIds, array $latest): array
{
    $saved = new class {
        /** @var array<int,array{number: string|null, data: array}> pre-existing rows, in order */
        public array $rows = [];
        /** @var array<int,array{number: string|null, data: array}> rows this run created */
        public array $created = [];
    };

    $existing = [];

    foreach ($consignmentIds as $index => $consignmentId) {
        $saved->rows[$index] = ['number' => null, 'data' => []];

        $existing[] = recordingTrack(
            [
                'setTrackNumber' => static function ($number) use ($saved, $index): void {
                    $saved->rows[$index]['number'] = $number;
                },
                'setData'        => static function ($key, $value) use ($saved, $index): void {
                    $saved->rows[$index]['data'][$key] = $value;
                },
            ],
            ['getData' => static function (?string $key = null) use ($consignmentId) {
                return 'myparcel_consignment_id' === $key ? $consignmentId : null;
            }]
        );
    }

    $shipment = createShipment();

    $exportService = Mockery::mock(MyParcelNL\Magento\Service\Export\ShipmentExportService::class);
    $exportService->shouldReceive('fetchLatest')->andReturn($latest);

    $collection = partialOrderCollection([$shipment]);
    $collection->shouldReceive('tracksByShipmentId')->andReturn([(int) $shipment->getId() => $existing]);
    $collection->shouldReceive('getMyparcelConsignmentIdsByApiKey')
               ->andReturn(['key' => array_values(array_unique($consignmentIds))]);
    $collection->shouldReceive('updateOrderGrid')->andReturnSelf();
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(static function () use ($saved) {
        $key = array_push($saved->created, ['number' => null, 'data' => []]) - 1;

        return recordingTrack([
            'setTrackNumber' => static function ($number) use ($saved, $key): void {
                $saved->created[$key]['number'] = $number;
            },
            'setData'        => static function ($field, $value) use ($saved, $key): void {
                $saved->created[$key]['data'][$field] = $value;
            },
        ]);
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

/**
 * @param array<int,array{number: string, carrier?: string, consignmentId?: int}> $tracks
 *
 * @return array{0: MagentoOrderCollection, 1: object} the collection, and a recorder that keeps
 *         what was written to the tracks that already existed apart from what was written to the
 *         tracks this run created
 */
function collectionWithTracks(string $incrementId, array $tracks, int $shipmentCount = 1): array
{
    $saved = new class {
        /** @var string[] numbers written to tracks that already existed */
        public array $numbers = [];
        /** @var array<string,mixed> data set on tracks that already existed, last write wins */
        public array $data = [];
        /** @var string[] numbers written to tracks created by this run */
        public array $createdNumbers = [];
        /** @var int[] every myparcel_consignment_id written, existing and created */
        public array $ids = [];
    };

    $makeTrack = static function (array $track, bool $isNew) use ($saved) {
        return recordingTrack(
            [
                'setTrackNumber' => static function ($number) use ($saved, $isNew): void {
                    if ($isNew) {
                        $saved->createdNumbers[] = $number;
                    } else {
                        $saved->numbers[] = $number;
                    }
                },
                'setData'        => static function ($key, $value) use ($saved): void {
                    $saved->data[$key] = $value;

                    if ('myparcel_consignment_id' === $key) {
                        $saved->ids[] = $value;
                    }
                },
            ],
            [
                'getCarrierCode' => $track['carrier'] ?? Carrier::CODE,
                'getTrackNumber' => $track['number'],
                'getData'        => static function (string $key) use ($track) {
                    return 'myparcel_consignment_id' === $key ? ($track['consignmentId'] ?? null) : null;
                },
            ]
        );
    };

    $order        = createOrder(['getIncrementId' => $incrementId]);
    $shipmentList = [];
    $tracksById   = [];

    // Each Magento shipment carries its own track rows, which is what the id-keyed map expresses.
    for ($i = 0; $i < $shipmentCount; $i++) {
        $shipmentId     = 123 + $i;
        $shipmentList[] = createShipment([
            'getOrder'    => $order,
            'getId'       => $shipmentId,
            'getEntityId' => $shipmentId,
        ]);

        $tracksById[$shipmentId] = array_map(static function (array $track) use ($makeTrack) {
            return $makeTrack($track, false);
        }, $tracks);
    }

    $collection = partialOrderCollection($shipmentList);
    $collection->shouldReceive('getOrders')->andReturn([$order]);
    $collection->shouldReceive('tracksByShipmentId')->andReturn($tracksById);
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(
        static function () use ($makeTrack) {
            return $makeTrack(['number' => TrackAndTrace::VALUE_EMPTY], true);
        }
    );

    return [$collection, $saved];
}
