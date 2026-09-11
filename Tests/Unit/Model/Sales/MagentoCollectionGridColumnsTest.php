<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Service\OrderGridColumns;

/**
 * updateOrderGrid() hands the page's order ids to OrderGridColumns once, distinct, without loading
 * an order. It used to load and save the order of every shipment, so an order with two shipments
 * was written twice.
 *
 * createShipment() lives in Tests/Helpers/OrderMocks.php.
 */
it('writes the grid columns once per distinct order of the page', function () {
    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturn(new ArrayIterator([
        createShipment(['getOrderId' => 7]),
        createShipment(['getOrderId' => 7]),
        createShipment(['getOrderId' => 9]),
    ]));

    $gridColumns = Mockery::mock(OrderGridColumns::class);
    $gridColumns->shouldReceive('writeFor')->once()->with([7, 9])->andReturn(2);

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);
    setPrivateProperty($collection, 'gridColumns', $gridColumns);

    invokePrivateMethod($collection, 'updateOrderGrid');
});
