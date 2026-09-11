<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

// collectionRefreshing() and apiShipment() live in Tests/Helpers/UpdateMagentoTrackFixtures.php.

it('stores the consumer portal link the API returned', function () {
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipment(4242, '3SMYPA123', 'https://myparcel.me/track-trace/3SMYPA123/1234AB/NL'),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->data['myparcel_tracktrace_url'])->toBe('https://myparcel.me/track-trace/3SMYPA123/1234AB/NL')
        ->and($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data['myparcel_status'])->toBe(3);
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

it('reads the shipments fresh, so a refresh sees ids written in the same pass', function () {
    // A shared collection loads once and its Shipment objects cache their own tracks, so
    // updateMagentoTrack() would read the tracks as they were before setFulfilmentTrackData()
    // wrote to them. A track with a barcode leaves the cron's scope, so a link missed on that
    // pass is missed for good.
    $created = 0;

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with(MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection::PATH_MODEL_SHIPMENT_COLLECTION)
        ->andReturnUsing(static function () use (&$created) {
            $created++;

            $collection = Mockery::mock(
                Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
            );
            $collection->shouldReceive('addAttributeToFilter')->andReturnSelf();
            $collection->shouldReceive('getIterator')->andReturn(new ArrayIterator([]));

            return $collection;
        });

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);
    setPrivateProperty($collection, 'orders', []);

    invokePrivateMethod($collection, 'getShipmentsCollection');
    invokePrivateMethod($collection, 'getShipmentsCollection');

    expect($created)->toBe(2);
});
