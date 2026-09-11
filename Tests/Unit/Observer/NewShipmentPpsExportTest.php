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
