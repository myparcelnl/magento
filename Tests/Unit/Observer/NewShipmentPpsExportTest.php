<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Observer\NewShipment;

/**
 * PPS export from the New Shipment page. Magento saves $shipment->getOrder() once more after this
 * observer, so the uuid setFulfilment() stores has to land on that same instance. Exporting a
 * reload of the order puts it on a copy, and the later save writes myparcel_uuid back to null.
 *
 * createOrder() and createShipment() live in Tests/Helpers/OrderMocks.php.
 */

it('exports the order instance the shipment holds, not a reload of it', function () {
    $order    = createOrder();
    $shipment = createShipment(['getOrder' => $order]);

    $exported   = null;
    $collection = Mockery::mock(MagentoOrderCollection::class);
    $collection->shouldReceive('setOrderCollection')
               ->once()
               ->andReturnUsing(function ($orders) use (&$exported, $collection) {
                   $exported = $orders;

                   return $collection;
               });
    $collection->shouldReceive('setFulfilment')->once()->andReturnSelf();

    $observer = newInstanceWithoutConstructor(NewShipment::class);
    setPrivateProperty($observer, 'orderCollection', $collection);

    invokePrivateMethod($observer, 'exportEntireOrder', [$shipment]);

    expect($exported)->toBe([$order]);
});

it('writes the two grid columns without saving the order', function () {
    // $order->save() rewrote every column of a possibly stale order and re-synced the grid for it.
    // save() is deliberately left unstubbed: a call to it fails this test.
    $columns = ['track_status' => 'status_3', 'track_number' => '["3SA"]'];
    $set     = [];
    $written = [];

    $order = createOrder(['getEntityId' => 7]);
    $order->shouldReceive('setData')->andReturnUsing(function ($key, $value) use ($order, &$set) {
        $set[$key] = $value;

        return $order;
    });

    $shipment = createShipment(['getOrder' => $order, 'getTracksCollection' => []]);

    $collection = Mockery::mock(MagentoOrderCollection::class);

    $gridColumns = Mockery::mock(MyParcelNL\Magento\Service\OrderGridColumns::class);
    $gridColumns->shouldReceive('htmlForTracks')->andReturn($columns);
    $gridColumns->shouldReceive('writeColumns')->andReturnUsing(function (int $orderId, array $values) use (&$written) {
        $written[] = [$orderId, $values];

        return true;
    });

    $observer = newInstanceWithoutConstructor(NewShipment::class);
    setPrivateProperty($observer, 'orderCollection', $collection);
    setPrivateProperty($observer, 'gridColumns', $gridColumns);

    invokePrivateMethod($observer, 'updateTrackGrid', [$shipment, false]);

    // Set as well as written: Magento saves this order again after the observer.
    expect($written)->toBe([[7, $columns]])
        ->and($set)->toBe($columns);
});

it('marks a PPS export exported rather than reading it off the tracks', function () {
    $order = createOrder(['getEntityId' => 7]);
    $order->shouldReceive('setData')->andReturnSelf();

    $shipment = createShipment(['getOrder' => $order, 'getTracksCollection' => []]);

    $collection = Mockery::mock(MagentoOrderCollection::class);

    $written     = [];
    $gridColumns = Mockery::mock(MyParcelNL\Magento\Service\OrderGridColumns::class);
    $gridColumns->shouldReceive('htmlForTracks')->andReturn(['track_status' => '', 'track_number' => '']);
    $gridColumns->shouldReceive('writeColumns')->andReturnUsing(function (int $orderId, array $values) use (&$written) {
        $written = $values;

        return true;
    });

    $observer = newInstanceWithoutConstructor(NewShipment::class);
    setPrivateProperty($observer, 'orderCollection', $collection);
    setPrivateProperty($observer, 'gridColumns', $gridColumns);

    invokePrivateMethod($observer, 'updateTrackGrid', [$shipment, true]);

    expect($written['track_status'])->toBe(MyParcelNL\Magento\Cron\UpdateStatus::ORDER_STATUS_EXPORTED);
});
