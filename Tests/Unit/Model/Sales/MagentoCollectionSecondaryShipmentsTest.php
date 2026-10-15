<?php

declare(strict_types=1);

/**
 * A multicollo is created as one shipment carrying secondary_shipments, and the module keeps one
 * Track for the whole of it — the SDK's create response drops the secondaries, so colli 2..N never
 * had an id to store. The query response does carry them, so updateMagentoTrack() makes the missing
 * rows from what it already fetched. Both export modes go through here, so this is the one
 * place multicollo barcodes can appear.
 *
 * collectionRefreshing(), apiShipment(), apiShipmentWithColli() and apiCollo() live in
 * Tests/Helpers/UpdateMagentoTrackFixtures.php.
 */

it('gives every collo of a multicollo its own track', function () {
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [
            apiCollo(4243, '3SCOLLO2', 'https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL'),
            apiCollo(4244, '3SCOLLO3'),
        ]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->numbers)->toBe(['3SMAIN'])
        ->and($saved->created)->toHaveCount(2)
        ->and($saved->created[0]['number'])->toBe('3SCOLLO2')
        ->and($saved->created[0]['data']['myparcel_consignment_id'])->toBe(4243)
        ->and($saved->created[0]['data']['myparcel_tracktrace_url'])
        ->toBe('https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL')
        ->and($saved->created[1]['number'])->toBe('3SCOLLO3')
        ->and($saved->created[1]['data']['myparcel_consignment_id'])->toBe(4244);
});

it('carries the status onto a collo track as well', function () {
    // Without it the grid shows the collo as status-less next to its own parent.
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2', null, 5)]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created[0]['data']['myparcel_status'])->toBe(5);
});

it('adds no collo track on a second run', function () {
    // The guard is the id already being on a track — the same key the refresh works by. Without it
    // every run would add another row for the same collo.
    [$collection, $saved] = collectionRefreshing(4243, [
        4243 => apiShipmentWithColli(4243, '3SCOLLO2', [apiCollo(4243, '3SCOLLO2')]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toBe([]);
});

it('leaves an ordinary shipment alone', function () {
    // secondary_shipments is never set on a single shipment, so the getter answers null. Nothing
    // may iterate that blind.
    [$collection, $saved] = collectionRefreshing(4242, [4242 => apiShipment(4242, '3SMYPA123', null)]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toBe([])
        ->and($saved->numbers)->toBe(['3SMYPA123']);
});

it('treats an empty secondary_shipments array as no colli', function () {
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMYPA123', []),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toBe([]);
});

it('skips a collo the response gave no id', function () {
    // Nothing could refresh such a track, and nothing could stop it being made again next run.
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(0, '3SCOLLO2')]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toBe([]);
});

it('gives a collo the spare row persist parked, instead of adding one', function () {
    // label_amount 3 makes three rows up front. persist() puts the parent's id on all three, so
    // rows two and three are spares. Left as they were they would sit at the parent id for good,
    // while two fresh rows appeared beside them.
    [$collection, $saved] = collectionRefreshingTracks([4242, 4242, 4242], [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [
            apiCollo(4243, '3SCOLLO2'),
            apiCollo(4244, '3SCOLLO3'),
        ]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toBe([])
        ->and($saved->rows[0]['number'])->toBe('3SMAIN')
        ->and($saved->rows[1]['number'])->toBe('3SCOLLO2')
        ->and($saved->rows[1]['data']['myparcel_consignment_id'])->toBe(4243)
        ->and($saved->rows[2]['number'])->toBe('3SCOLLO3')
        ->and($saved->rows[2]['data']['myparcel_consignment_id'])->toBe(4244);
});

it('never refreshes a spare row as though it were the parent', function () {
    // Both rows carry the parent id, so reading them alike would write the parent's barcode onto
    // the collo row and leave it there until something else overwrote it.
    [$collection, $saved] = collectionRefreshingTracks([4242, 4242], [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2')]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->rows[1]['number'])->toBe('3SCOLLO2');
});

it('adds a row when a multicollo has more colli than spare rows', function () {
    // label_amount 1 on a two-collo order: there is no spare, so the row is made as before.
    [$collection, $saved] = collectionRefreshingTracks([4242], [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2')]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created)->toHaveCount(1)
        ->and($saved->created[0]['data']['myparcel_consignment_id'])->toBe(4243);
});
it('asks for a collo by id, so the link its nested entry lacked arrives', function () {
    // The api fills link_consumer_portal in only for a shipment asked for by id. A collo read out of
    // secondary_shipments has none, and in PPS mode nothing ever asks again: ordersAwaitingBarcode()
    // drops the order the moment it has a barcode, so the grid falls back to a built url for good.
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2')]),
        4243 => apiShipment(4243, '3SCOLLO2', 'https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL'),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->created[0]['data']['myparcel_tracktrace_url'])
        ->toBe('https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL');
});

it('asks about the colli alone, never about the parent again', function () {
    // The parents were read moments ago and nothing about them has changed.
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2'), apiCollo(4244, '3SCOLLO3')]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->fetched)->toHaveCount(2)
        ->and($saved->fetched[1])->toBe(['key' => [4243, 4244]]);
});

it('asks nothing more when the nested entries carried their links', function () {
    // The day the api sends them, this call stops happening on its own.
    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [
            apiCollo(4243, '3SCOLLO2', 'https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL'),
        ]),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->fetched)->toHaveCount(1);
});

it('asks nothing more for an order that is no multicollo', function () {
    [$collection, $saved] = collectionRefreshing(4242, [4242 => apiShipment(4242, '3SMYPA123', null)]);

    $collection->updateMagentoTrack();

    expect($saved->fetched)->toHaveCount(1);
});

it('gives a spare row its link as well', function () {
    // The spare path writes no link either: it is handed the same nested collo.
    [$collection, $saved] = collectionRefreshingTracks([4242, 4242], [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2')]),
        4243 => apiShipment(4243, '3SCOLLO2', 'https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL'),
    ]);

    $collection->updateMagentoTrack();

    expect($saved->rows[1]['data']['myparcel_tracktrace_url'])
        ->toBe('https://myparcel.me/track-trace/3SCOLLO2/1234AB/NL');
});
