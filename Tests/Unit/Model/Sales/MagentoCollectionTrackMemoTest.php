<?php

declare(strict_types=1);

use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;

/**
 * The track memo is keyed by shipment id, so a collection handed a second set of orders answers the
 * new ones with the old ones' rows unless the setter clears it. The status cron does exactly that:
 * the PPS pass runs first, then the status poll replaces the collection on the same object.
 *
 * partialOrderCollection() lives in Tests/Helpers/UpdateMagentoTrackFixtures.php.
 */

it('forgets the tracks it read when the order collection is replaced', function () {
    $collection = partialOrderCollection([]);
    setPrivateProperty($collection, 'trackMemo', [7 => ['a track']]);

    $collection->setOrderCollection(Mockery::mock(OrderCollection::class));

    expect(getPrivateProperty($collection, 'trackMemo'))->toBeNull();
});

it('forgets the tracks it read when the shipment collection is replaced', function () {
    // Unconstructed: the setter touches two fields, and the constructor wants a live ObjectManager.
    $collection = newInstanceWithoutConstructor(MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection::class);
    setPrivateProperty($collection, 'trackMemo', [7 => ['a track']]);

    $collection->setShipmentCollection(Mockery::mock(ShipmentCollection::class));

    expect(getPrivateProperty($collection, 'trackMemo'))->toBeNull();
});
