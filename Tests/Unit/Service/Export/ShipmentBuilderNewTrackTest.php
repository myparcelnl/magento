<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\Export\ShipmentBuilder;

it('gives a new track the order id and the parent id that TrackRepository::save() checks', function () {
    $track = Mockery::mock(Track::class);
    $track->shouldReceive('setShipment', 'setCarrierCode', 'setTitle', 'setQty', 'setTrackNumber')->andReturnSelf();
    $track->shouldReceive('setOrderId')->with(1)->once()->andReturnSelf();
    $track->shouldReceive('setParentId')->with(123)->once()->andReturnSelf();

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(Track::class)->andReturn($track);

    expect(ShipmentBuilder::newTrackFor($objectManager, createShipment(['getId' => 123, 'getOrderId' => 1, 'getTotalQty' => 1.0])))
        ->toBe($track);
});
