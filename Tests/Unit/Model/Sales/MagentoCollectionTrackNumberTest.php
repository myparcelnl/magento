<?php

declare(strict_types=1);

use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * A PPS order's barcode and shipment id must reach the track that would otherwise keep a
 * placeholder. The other half of that defect, a placeholder never reaching sales_order.track_number,
 * is pinned in OrderGridColumnsTest, where the rule lives.
 *
 * The allocation is the other half again: a fulfilment order can hold several separate
 * shipments, each needs its own track, and the cron runs every minute — so what is pinned hardest
 * here is that a second run writes nothing. Multicollo colli are not this path; they are expanded
 * from the shipment API by updateMagentoTrack().
 */

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
