<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment\Track;

use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

/**
 * A MagentoOrderCollection over one Magento shipment, counting how often the track and shipment
 * collections were created. The counts are filled by reference, so a test reads them after the fact.
 *
 * @param Track[] $tracks what the track query answers with
 */
function memoisedTrackCollection(array $tracks, ?int &$queries, ?int &$shipmentLoads = null): MagentoOrderCollection
{
    $queries       = 0;
    $shipmentLoads = 0;
    $shipment      = createShipment(['getId' => 7, 'getEntityId' => 7]);

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);

    $objectManager->shouldReceive('create')
        ->with(MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection::PATH_MODEL_SHIPMENT_COLLECTION)
        ->andReturnUsing(static function () use ($shipment, &$shipmentLoads) {
            $shipmentLoads++;

            $collection = Mockery::mock(
                Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
            );
            $collection->shouldReceive('addAttributeToFilter')->andReturnSelf();
            $collection->shouldReceive('getIterator')->andReturnUsing(static function () use ($shipment) {
                return new ArrayIterator([$shipment]);
            });

            return $collection;
        });

    $objectManager->shouldReceive('create')
        ->with('\\Magento\\Sales\\Model\\ResourceModel\\Order\\Shipment\\Track\\Collection')
        ->andReturnUsing(static function () use ($tracks, &$queries) {
            $queries++;

            $collection = Mockery::mock(
                Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class
            );
            $collection->shouldReceive('addAttributeToFilter', 'setResetItemsDataChanged')->andReturnSelf();
            $collection->shouldReceive('getItems')->andReturn($tracks);

            return $collection;
        });

    // setNewMagentoTrack() builds one through the object manager and saves it. Every setter has to
    // answer with the track: newTrackFor() chains seven of them.
    $objectManager->shouldReceive('create')
        ->with(Track::class)
        ->andReturnUsing(static function () {
            $track = Mockery::mock(Track::class);
            $track->shouldReceive(
                'setOrderId',
                'setParentId',
                'setShipment',
                'setCarrierCode',
                'setTitle',
                'setQty',
                'setTrackNumber',
                'setData'
            )->andReturnSelf();
            $track->shouldReceive('save')->andReturnSelf();

            return $track;
        });

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);
    setPrivateProperty($collection, 'orders', []);
    setPrivateProperty($collection, 'trackRepository', savingTrackRepository());

    return $collection;
}

it('stores the consumer portal link the API returned', function () {
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipment(4242, '3SMYPA123', 'https://myparcel.me/track-trace/3SMYPA123/1234AB/NL'),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->data['myparcel_tracktrace_url'])->toBe('https://myparcel.me/track-trace/3SMYPA123/1234AB/NL')
        ->and($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data['myparcel_status'])->toBe('3');
});

it('does not blank a stored link when the response carries none', function () {
    // A concept has no link yet, and the spec allows an empty string where a uri belongs.
    [$collection, $saved] = collectionRefreshing(4242, [4242 => apiShipment(4242, '3SMYPA123', null)]);

    $collection->updateMagentoTrack();

    expect($saved->data)->not->toHaveKey('myparcel_tracktrace_url');
});

it('does not blank a stored link on an empty string', function () {
    [$collection, $saved] = collectionRefreshing(4242, [4242 => apiShipment(4242, '3SMYPA123', '')]);

    $collection->updateMagentoTrack();

    expect($saved->data)->not->toHaveKey('myparcel_tracktrace_url');
});

it('leaves a track the response said nothing about untouched', function () {
    // This is the shape a PPS track has before its shipment id is stored: nothing to refresh by.
    [$collection, $saved] = collectionRefreshing(0, []);

    $collection->updateMagentoTrack();

    expect($saved->saves)->toBe(0)
        ->and($saved->data)->toBe([])
        ->and($saved->numbers)->toBe([]);
});

it('reads the tracks from their own query once, and hands every caller the same objects', function () {
    // A Shipment caches its own tracks with no way to reset them, so reading from it misses what
    // setOrderV1TrackData() wrote. updateMagentoTrack() then reads that shipment id back off these
    // objects, so fresh instances lose it unless every writer saves first.
    $track      = recordingTrack([], ['getParentId' => 7]);
    $collection = memoisedTrackCollection([$track], $queries, $shipmentLoads);

    $first  = invokePrivateMethod($collection, 'tracksByShipmentId');
    $second = invokePrivateMethod($collection, 'tracksByShipmentId');

    expect($first[7][0])->toBe($second[7][0])
        ->and($queries)->toBe(1)
        ->and($shipmentLoads)->toBe(1);
});

it('reads again once a row has been added, which the memo cannot describe', function () {
    $collection = memoisedTrackCollection([], $queries);

    invokePrivateMethod($collection, 'tracksByShipmentId');
    invokePrivateMethod($collection, 'setNewMagentoTrack', [createShipment(['getId' => 7, 'getEntityId' => 7, 'getTotalQty' => 1, 'getOrderId' => 3])]);
    invokePrivateMethod($collection, 'tracksByShipmentId');

    expect($queries)->toBe(2);
});

it('reads the tracks of every shipment in one query rather than one per shipment', function () {
    $filters = [];

    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturnUsing(static function () {
        return new ArrayIterator([
            createShipment(['getId' => 7, 'getEntityId' => 7]),
            createShipment(['getId' => 8, 'getEntityId' => 8]),
            createShipment(['getId' => 9, 'getEntityId' => 9]),
        ]);
    });

    $tracks = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class);
    $tracks->shouldReceive('addAttributeToFilter')->andReturnUsing(
        static function ($field, $condition) use ($tracks, &$filters) {
            $filters[] = [$field, $condition];

            return $tracks;
        }
    );
    $tracks->shouldReceive('getItems')->andReturn([]);
    // Without it every loaded track counts as changed, and the refresh saves each one.
    $tracks->shouldReceive('setResetItemsDataChanged')->with(true)->once()->andReturnSelf();

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->andReturn($tracks);

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);
    setPrivateProperty($collection, 'objectManager', $objectManager);

    invokePrivateMethod($collection, 'tracksByShipmentId');

    // An order can carry a manual DHL track; its barcode must not reach sales_order.track_number.
    expect($filters)->toBe([
        ['parent_id', ['in' => [7, 8, 9]]],
        ['carrier_code', Carrier::CODE],
    ]);
});

it('does not save a track the refresh left unchanged', function () {
    // AbstractDb::save() opens and commits a transaction before it notices an unmodified model.
    [$collection, $saved] = collectionRefreshing(
        4242,
        [4242 => apiShipment(4242, '3SMYPA123', null)],
        false
    );

    $collection->updateMagentoTrack();

    expect($saved->saves)->toBe(0);
});

it('saves a track the refresh did change', function () {
    [$collection, $saved] = collectionRefreshing(4242, [4242 => apiShipment(4242, '3SMYPA123', null)]);

    $collection->updateMagentoTrack();

    expect($saved->saves)->toBe(1);
});
