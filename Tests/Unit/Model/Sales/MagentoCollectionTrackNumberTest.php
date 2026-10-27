<?php

declare(strict_types=1);

use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * An Order v1 order's barcode and shipment id must reach the track that would otherwise keep a
 * placeholder. An Order v1 order can hold several shipments, each needs its own track, and the
 * cron runs every minute, so a second run must write nothing. Multicollo colli are not this path:
 * updateMagentoTrack() expands them from the shipment API.
 */

it('writes the barcode and the shipment id onto a track still holding a placeholder', function () {
    // The id lets updateMagentoTrack() refresh the status and track & trace link afterwards.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data)->toBe(['myparcel_shipment_id' => 4242])
        ->and($saved->createdNumbers)->toBe([]);
});

it('writes the barcode without an id when the response named none, and says so', function () {
    // Without the id there is nothing to refresh by, so that track gets no status and no track &
    // trace link, ever. It is the one case worth a warning.
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->andReturnNull();

    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => null]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->data)->toBe([]);
});

it('replaces the printed placeholder once a real barcode arrives', function () {
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_PRINTED]]);

    $collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => null]]]);

    expect($saved->numbers)->toBe(['3SMYPA123']);
});

it('leaves a track that already carries a barcode alone, and gives the new shipment its own', function () {
    // The existing track is never rewritten.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => '3SEXISTING']]);

    $collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe(['3SMYPA123'])
        ->and($saved->ids)->toBe([4242]);
});

it('does nothing for an order the Order v1 response said nothing about', function () {
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setOrderV1TrackData(['100000999' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe([]);
});

it('gives a second shipment its own track when only one placeholder is free', function () {
    // Two colli, one Magento shipment, one placeholder track. An order that ends up with more than
    // one track logs a notice.
    $logger = mockLoggerFacade();
    $logger->shouldReceive('notice')->once()->andReturnNull();

    [$collection, $saved] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $collection->setOrderV1TrackData(['100000001' => [
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
        ['number' => '3SA', 'myParcelShipmentId' => 1],
        ['number' => '3SB', 'myParcelShipmentId' => 2],
    ]);

    $collection->setOrderV1TrackData(['100000001' => [
        ['barcode' => '3SA', 'shipmentId' => 1],
        ['barcode' => '3SB', 'shipmentId' => 2],
    ]]);

    expect($saved->numbers)->toBe([])
        ->and($saved->createdNumbers)->toBe([])
        ->and($saved->ids)->toBe([]);
});

it('creates no track for a shipment with neither an id nor a real barcode', function () {
    // Nothing could ever match it again, so a new track would be added every single run.
    [$collection, $saved] = collectionWithTracks('100000001', [['number' => '3SA', 'myParcelShipmentId' => 1]]);

    $collection->setOrderV1TrackData(['100000001' => [
        ['barcode' => TrackAndTrace::VALUE_PRINTED, 'shipmentId' => null],
    ]]);

    expect($saved->createdNumbers)->toBe([])
        ->and($saved->numbers)->toBe([]);
});

it('claims a shipment once for an order that has two Magento shipments', function () {
    // An order shipped partly by hand has two Magento shipments; only one track gets the barcode.
    [$collection, $saved] = collectionWithTracks(
        '100000001',
        [['number' => TrackAndTrace::VALUE_EMPTY]],
        2
    );

    $collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]);

    expect($saved->numbers)->toBe(['3SMYPA123'])
        ->and($saved->createdNumbers)->toBe([])
        ->and($saved->ids)->toBe([4242]);
});

it('logs and skips an order whose track cannot be saved, so the run goes on', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->with('MyParcel: could not save the tracks of order 100000001', Mockery::type('array'))->once();

    [$collection] = collectionWithTracks('100000001', [['number' => TrackAndTrace::VALUE_EMPTY]]);

    $repository = Mockery::mock(Magento\Sales\Api\ShipmentTrackRepositoryInterface::class);
    $repository->shouldReceive('save')->andThrow(new RuntimeException('deadlock'));
    setPrivateProperty($collection, 'trackRepository', $repository);

    expect($collection->setOrderV1TrackData(['100000001' => [['barcode' => '3SMYPA123', 'shipmentId' => 4242]]]))
        ->toBe($collection);
});
