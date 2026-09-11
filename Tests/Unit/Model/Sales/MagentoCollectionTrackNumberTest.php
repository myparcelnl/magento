<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * Two halves of the same defect: a placeholder must not reach sales_order.track_number, and a PPS
 * order's barcode and shipment id must reach the track that would otherwise keep it.
 *
 * The allocation is the other half again: a fulfilment order can hold several separate
 * shipments, each needs its own track, and the cron runs every minute — so what is pinned hardest
 * here is that a second run writes nothing. Multicollo colli are not this path; they are expanded
 * from the shipment API by updateMagentoTrack().
 */

it('does not treat a placeholder as a track number', function () {
    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);

    $html = $collection->getHtmlForGridColumnsByTracks([
        ['track_number' => TrackAndTrace::VALUE_EMPTY, 'myparcel_status' => null],
        ['track_number' => TrackAndTrace::VALUE_PRINTED, 'myparcel_status' => null],
    ]);

    expect($html['track_number'])->toBe('');
});

it('still reports a real barcode alongside a placeholder', function () {
    $collection = newInstanceWithoutConstructor(MagentoOrderCollection::class);

    $html = $collection->getHtmlForGridColumnsByTracks([
        ['track_number' => '3SMYPA123', 'myparcel_status' => null],
        ['track_number' => TrackAndTrace::VALUE_EMPTY, 'myparcel_status' => null],
    ]);

    expect($html['track_number'])->toBe(json_encode(['3SMYPA123']));
});

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
        $mock = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
        $mock->shouldReceive('getCarrierCode')->andReturn($track['carrier'] ?? Carrier::CODE);
        $mock->shouldReceive('getTrackNumber')->andReturn($track['number']);
        $mock->shouldReceive('getData')->andReturnUsing(
            static function (string $key) use ($track) {
                return 'myparcel_consignment_id' === $key ? ($track['consignmentId'] ?? null) : null;
            }
        );
        $mock->shouldReceive('setTrackNumber')->andReturnUsing(
            static function ($number) use ($mock, $saved, $isNew) {
                if ($isNew) {
                    $saved->createdNumbers[] = $number;
                } else {
                    $saved->numbers[] = $number;
                }

                return $mock;
            }
        );
        $mock->shouldReceive('setData')->andReturnUsing(
            static function ($key, $value) use ($mock, $saved) {
                $saved->data[$key] = $value;

                if ('myparcel_consignment_id' === $key) {
                    $saved->ids[] = $value;
                }

                return $mock;
            }
        );
        $mock->shouldReceive('save')->andReturnSelf();

        return $mock;
    };

    $trackMocks = array_map(static function (array $track) use ($makeTrack) {
        return $makeTrack($track, false);
    }, $tracks);

    $order        = createOrder(['getIncrementId' => $incrementId]);
    $shipmentList = [];

    for ($i = 0; $i < $shipmentCount; $i++) {
        $shipmentList[] = createShipment(['getOrder' => $order]);
    }

    // IteratorAggregate is named explicitly: the test bootstrap stubs uninstalled Magento classes
    // as empty ones, so a mock of the collection alone iterates nothing.
    $shipments = Mockery::mock(
        Magento\Sales\Model\ResourceModel\Order\Shipment\Collection::class . ', IteratorAggregate'
    );
    $shipments->shouldReceive('getIterator')->andReturnUsing(
        static function () use ($shipmentList) {
            return new ArrayIterator($shipmentList);
        }
    );

    $trackCollection = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class);
    $trackCollection->shouldReceive('getItems')->andReturn($trackMocks);

    $collection = Mockery::mock(MagentoOrderCollection::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
    $collection->shouldReceive('getShipmentsCollection')->andReturn($shipments);
    $collection->shouldReceive('getTrackByShipment')->andReturn($trackCollection);
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(
        static function () use ($makeTrack) {
            return $makeTrack(['number' => TrackAndTrace::VALUE_EMPTY], true);
        }
    );

    return [$collection, $saved];
}

it('writes the barcode and the shipment id onto a track still holding a placeholder', function () {
    // The id is what lets updateMagentoTrack() refresh this order afterwards, status and track &
    // trace link included — which a PPS order never got.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data)->toBe(['myparcel_consignment_id' => 4242])
        ->and($saved->createdNumbers)->toBe([]);
});

it('writes the barcode without an id when the response named none, and says so', function () {
    // Without the id there is nothing to refresh by, so that track gets no status and no track &
    // trace link, ever. It is the one case worth a warning.
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->andReturnNull();

    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => null]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data)->toBe([]);
});

it('replaces the printed placeholder once a real barcode arrives', function () {
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_PRINTED]]);

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => null]]]);

    expect($saved->numbers)->toBe(['3SMYPA123']);
});

it('leaves a track that already carries a barcode alone, and gives the new shipment its own', function () {
    // The shipment used to be dropped here, which is how every collo after the first
    // stayed invisible. The existing track is still never rewritten.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => '3SEXISTING']]);

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe(['3SMYPA123'])
        ->and($saved->ids)->toBe([4242]);
});

it('touches no track of another carrier', function () {
    [$collection, $saved] = collectionWithTracks('100000001', [
        ['number' => TrackAndTrace::VALUE_EMPTY, 'carrier' => 'dhl'],
    ]);

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe(['3SMYPA123']);
});

it('does nothing for an order the fulfilment response said nothing about', function () {
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setFulfilmentTrackData(['100000999' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe([]);
});

it('gives a second shipment its own track when only one placeholder is free', function () {
    // The shape of the reported bug: two colli, one Magento shipment, one placeholder track.
    // An order that ends up with more than one track says so in system.log; it is new behaviour.
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->once()->andReturnNull();

    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setFulfilmentTrackData(['100000001' => [
        ['barcode' => '3SA', 'shipmentId' => 1],
        ['barcode' => '3SB', 'shipmentId' => 2],
    ]]);

    expect($saved->numbers)->toBe(['3SA'])
        ->and($saved->createdNumbers)->toBe(['3SB'])
        ->and($saved->ids)->toBe([1, 2]);
});

it('adds nothing on a second run over the same response', function () {
    // The cron runs every minute. Both shipments are on tracks now, so this must be a no-op.
    [$collection, $saved] = collectionWithTracks('100000001', [
        ['number' => '3SA', 'consignmentId' => 1],
        ['number' => '3SB', 'consignmentId' => 2],
    ]);

    $collection->setFulfilmentTrackData(['100000001' => [
        ['barcode' => '3SA', 'shipmentId' => 1],
        ['barcode' => '3SB', 'shipmentId' => 2],
    ]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe([])
        ->and($saved->ids)->toBe([]);
});

it('creates no track for a shipment with neither an id nor a real barcode', function () {
    // Nothing could ever match it again, so a new track would be added every single run.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => '3SA', 'consignmentId' => 1]]);

    $collection->setFulfilmentTrackData(['100000001' => [
        ['barcode' => TrackAndTrace::VALUE_PRINTED, 'shipmentId' => null],
    ]]);

    expect($saved->createdNumbers)->toBe([])
        ->and($saved->numbers)->toBe([]);
});

it('claims a shipment once for an order that has two Magento shipments', function () {
    // The lookup used to run per Magento shipment, so an order shipped partly by hand got the same
    // barcode and the same id written onto a placeholder track of each.
    [$collection, $saved] = collectionWithTracks(
        '100000001',
        [['number' => TrackAndTrace::VALUE_EMPTY]],
        2
    );

    $collection->setFulfilmentTrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->createdNumbers)->toBe([])
        ->and($saved->ids)->toBe([4242]);
});
