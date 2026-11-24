<?php

declare(strict_types=1);

use Magento\Framework\DataObject;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Model\Sales\MagentoShipmentCollection;

/**
 * Pins that setMagentoTrack() counts a track of any carrier as "the shipment has a track".
 */

/**
 * A partial collection over shipment 7, whose only track belongs to another carrier. The track
 * query applies a carrier_code filter the way the database would.
 *
 * @param class-string<MagentoCollection> $class
 *
 * @return array{0: MagentoCollection, 1: object} the collection and a counter of new tracks
 */
function collectionWithForeignTrack(string $class): array
{
    $created = new class {
        public int $tracks = 0;
    };

    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturnUsing(static function () {
        return new ArrayIterator([createShipment(['getId' => 7, 'getEntityId' => 7])]);
    });
    $shipments->shouldReceive('save')->andReturnSelf();

    $rows          = [new DataObject(['parent_id' => 7, 'carrier_code' => 'dhl', 'track_number' => 'JVGL1'])];
    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with('\\Magento\\Sales\\Model\\ResourceModel\\Order\\Shipment\\Track\\Collection')
        ->andReturnUsing(static function () use ($rows) {
            $carrier = null;
            $query   = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class);
            $query->shouldReceive('addAttributeToFilter')->andReturnUsing(
                static function ($field, $condition) use ($query, &$carrier) {
                    if ('carrier_code' === $field) {
                        $carrier = $condition;
                    }

                    return $query;
                }
            );
            $query->shouldReceive('getItems')->andReturnUsing(static function () use ($rows, &$carrier) {
                return array_values(array_filter($rows, static function (DataObject $row) use (&$carrier) {
                    return null === $carrier || $carrier === $row->getData('carrier_code');
                }));
            });

            return $query;
        });

    $collection = Mockery::mock($class)->makePartial()->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(static function () use ($created) {
        $created->tracks++;
    });
    setPrivateProperty($collection, 'objectManager', $objectManager);
    $collection->setOption('create_track_if_one_already_exist', false);
    $collection->setOption('label_amount', 1);

    return [$collection, $created];
}

it('adds no MyParcel track to an order shipment that has another carrier\'s track', function () {
    [$collection, $created] = collectionWithForeignTrack(MagentoOrderCollection::class);

    $collection->setMagentoTrack();

    expect($created->tracks)->toBe(0);
});

it('adds no MyParcel track to a shipment that has another carrier\'s track', function () {
    [$collection, $created] = collectionWithForeignTrack(MagentoShipmentCollection::class);

    $collection->setMagentoTrack();

    expect($created->tracks)->toBe(0);
});

it('keeps another carrier\'s track out of the MyParcel tracks', function () {
    [$collection] = collectionWithForeignTrack(MagentoOrderCollection::class);

    expect(invokePrivateMethod($collection, 'tracksByShipmentId'))->toBe([]);
});
