<?php

declare(strict_types=1);

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;

it('loads the orders with their change flags reset', function () {
    // Magento's collection marks every loaded item as changed unless told otherwise.
    $orders = Mockery::mock(AbstractCollection::class);
    $orders->shouldReceive('setResetItemsDataChanged')->with(true)->once()->andReturnSelf();

    newInstanceWithoutConstructor(MagentoOrderCollection::class)->setOrderCollection($orders);
});

it('saves only the orders that this pass changed', function () {
    $unchanged = Mockery::mock(Order::class);
    $unchanged->shouldReceive('hasDataChanges')->andReturnFalse();
    $changed = Mockery::mock(Order::class);
    $changed->shouldReceive('hasDataChanges')->andReturnTrue();

    $resource = Mockery::mock(OrderResource::class);
    $resource->shouldReceive('save')->with($changed)->once();

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(OrderResource::class)->andReturn($resource);

    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);
    setPrivateProperty($collection, 'objectManager', $objectManager);
    $collection->setOrderCollection([$unchanged, $changed]);

    invokePrivateMethod($collection, 'save');
});
