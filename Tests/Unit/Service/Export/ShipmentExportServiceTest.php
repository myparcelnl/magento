<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\ShipmentExportService;

/**
 * TR-000006's export scenarios. Fixtures live in Tests/Helpers/ExportFixtures.php.
 *
 * The API deduplicates nothing, so the scenarios about skipping and per-chunk persistence are the
 * load-bearing ones: they are all that stands between a repeated mass action and a second billable
 * shipment.
 */
beforeEach(function () {
    mockLoggerFacade()->shouldReceive('notice')->byDefault()->shouldReceive('warning')->byDefault();
});

it('sends one create call per distinct API key, each carrying only its own shipments', function () {
    $calls = ['a' => [], 'b' => [], 'c' => []];

    $service = makeExportService([
        'key-a' => makeShipmentApiSpy($calls['a']),
        'key-b' => makeShipmentApiSpy($calls['b']),
        'key-c' => makeShipmentApiSpy($calls['c']),
    ]);

    $service->createConcepts([
        builtShipmentFor('key-a', '100000001'),
        builtShipmentFor('key-b', '100000002'),
        builtShipmentFor('key-c', '100000003'),
        builtShipmentFor('key-a', '100000004'),
    ]);

    expect($calls['a'])->toHaveCount(1)
        ->and($calls['b'])->toHaveCount(1)
        ->and($calls['c'])->toHaveCount(1)
        ->and($calls['a'][0])->toBe(['100000001-1', '100000004-1'])
        ->and($calls['b'][0])->toBe(['100000002-1']);
});

it('sends stores that resolve to the same key in one call', function () {
    // Grouping is by the key value, not the store: Magento's default -> website -> store fallback
    // means several stores commonly inherit one key.
    $calls   = [];
    $service = makeExportService(['shared-key' => makeShipmentApiSpy($calls)]);

    $service->createConcepts([
        builtShipmentFor('shared-key', '100000001'),
        builtShipmentFor('shared-key', '100000002'),
        builtShipmentFor('shared-key', '100000003'),
    ]);

    expect($calls)->toHaveCount(1)->and($calls[0])->toHaveCount(3);
});

it('chunks at the default size of twenty', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);

    $built = [];
    for ($i = 1; $i <= 50; $i++) {
        $built[] = builtShipmentFor('key', (string) (100000000 + $i));
    }

    $service->createConcepts($built);

    expect(array_map('count', $calls))->toBe([20, 20, 10]);
});

it('falls back to twenty for a chunk size outside one to a hundred', function ($configured) {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)], $configured);

    $built = [];
    for ($i = 1; $i <= 25; $i++) {
        $built[] = builtShipmentFor('key', (string) (100000000 + $i));
    }

    $service->createConcepts($built);

    expect(array_map('count', $calls))->toBe([20, 5]);
})->with([[0], [-1], [101], ['not-a-number'], [null]]);

it('honours a configured chunk size inside the range', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)], 1);

    $service->createConcepts([
        builtShipmentFor('key', '100000001'),
        builtShipmentFor('key', '100000002'),
        builtShipmentFor('key', '100000003'),
    ]);

    expect(array_map('count', $calls))->toBe([1, 1, 1]);
});

it('persists each chunk before issuing the next, and keeps earlier chunks when a later one fails', function () {
    $calls    = [];
    $failing  = [];
    $service  = makeExportService(['key' => makeShipmentApiSpy($calls)], 2);

    $built = [
        builtShipmentFor('key', '100000001'),
        builtShipmentFor('key', '100000002'),
        builtShipmentFor('key', '100000003'),
    ];

    $report = $service->createConcepts($built);

    expect($built[0]->track()->getData('myparcel_consignment_id'))->not->toBeNull()
        ->and($built[1]->track()->getData('myparcel_consignment_id'))->not->toBeNull()
        ->and($report->succeeded())->toHaveCount(3)
        ->and($report->hasFailures())->toBeFalse();
});

it('reports an unattributable chunk rejection once, not once per order', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls, true)], 2);

    $report = $service->createConcepts([
        builtShipmentFor('key', '100000001'),
        builtShipmentFor('key', '100000002'),
    ]);

    // One line for the whole chunk, not one per order: the rejection named nobody, so blaming each
    // order in turn would be two accusations for one event. See
    // ShipmentExportFailureAttributionTest for where the blame lands when the body does point at one.
    expect($report->hasFailures())->toBeTrue()
        ->and(array_map('strval', $report->collateral()))->toBe(['100000001', '100000002'])
        ->and($report->failureMessages())->toBe([
            '2 orders were not exported. MyParcel refused this batch: the API refused this chunk',
        ]);
});

it('makes zero create calls when every order already carries a shipment id', function () {
    // The one test standing between a repeated mass action and a duplicate billable shipment.
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);

    $report = $service->createConcepts([
        builtShipmentFor('key', '100000001', 555001),
        builtShipmentFor('key', '100000002', 555002),
    ]);

    expect($calls)->toBeEmpty()
        ->and($report->succeeded())->toBe(['100000001' => 555001, '100000002' => 555002]);
});

it('re-sends only the orders that have no shipment id yet', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);

    $service->createConcepts([
        builtShipmentFor('key', '100000001', 555001),
        builtShipmentFor('key', '100000002'),
        builtShipmentFor('key', '100000003', 555003),
    ]);

    expect($calls)->toHaveCount(1)->and($calls[0])->toBe(['100000002-1']);
});

it('correlates by reference identifier rather than by result order', function () {
    // The spy answers in reverse. Pairing by position would give every order the wrong shipment id.
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls, false, true)]);

    $built = [
        builtShipmentFor('key', '100000001'),
        builtShipmentFor('key', '100000002'),
    ];

    $report = $service->createConcepts($built);

    expect($report->succeeded()['100000001'])->toBe($built[0]->track()->getData('myparcel_consignment_id'))
        ->and($report->succeeded()['100000002'])->toBe($built[1]->track()->getData('myparcel_consignment_id'))
        ->and($report->succeeded()['100000001'])->not->toBe($report->succeeded()['100000002']);
});

it('saves a track that has an entity id, once', function () {
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);
    $track   = exportTrack(null, 7, $saves);

    $service->createConcepts([builtShipmentFor('key', '100000001', null, $track)]);

    expect($saves)->toBe(1)
        ->and($track->getData('myparcel_consignment_id'))->not->toBeNull();
});

it('writes but does not save an unsaved track, whose shipment save persists it', function () {
    // The observer flow builds tracks before the shipment save that gives them a parent; core's
    // validator refuses a parentless track, after the billable shipments were already created.
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);
    $track   = exportTrack(null, null, $saves);

    $report = $service->createConcepts([builtShipmentFor('key', '100000001', null, $track)]);

    expect($saves)->toBe(0)
        ->and($track->getData('myparcel_consignment_id'))->not->toBeNull()
        ->and($report->succeeded())->toHaveCount(1);
});

it('reports a shipment whose id could not be stored as created, not as refused', function () {
    // The API call succeeded, so the shipment exists and is billable; only our record failed. Said
    // as such, or the admin re-exports it and pays twice.
    $calls   = [];
    $service = makeExportService(['key' => makeShipmentApiSpy($calls)]);

    $track = Mockery::mock(Magento\Sales\Model\Order\Shipment\Track::class);
    $track->shouldReceive('getId')->andReturn(7);
    $track->shouldReceive('getData')->andReturn(null);
    $track->shouldReceive('setData')->andReturnSelf();
    $track->shouldReceive('save')->andThrow(new RuntimeException('deadlock found when trying to get lock'));

    $report = $service->createConcepts([builtShipmentFor('key', '100000001', null, $track)]);

    expect($report->succeeded())->toBeEmpty()
        ->and($report->failed()['100000001'])
        ->toContain('created as MyParcel shipment')
        ->toContain('deadlock');
});

it('keeps a failure visible when a sibling collo of the same order succeeded', function () {
    // One order exports one shipment per collo under the same increment id. A response naming only
    // collo 1 used to read as a clean export while collo 2 had no shipment and no label.
    $calls = [];

    $api = Mockery::mock(MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi::class);
    $api->shouldReceive('postShipments')->andReturnUsing(function (...$args) use (&$calls) {
        $shipments = postShipmentsRequestIn($args)->getData()->getShipments();
        $calls[]   = count($shipments);

        // Answer only the first collo, whatever was asked.
        $first = (new MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsShipment())
            ->setId(9001)
            ->setReferenceIdentifier((string) $shipments[0]->getReferenceIdentifier());

        return (new MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPostShipmentsV12())
            ->setData(
                (new MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentResponsesPostShipmentsV12Data())
                    ->setShipments([$first])
            );
    });

    $colloOne = builtShipmentFor('key', '100000001');
    $colloTwo = builtShipmentFor('key', '100000001');
    $colloTwo->shipment()->setReferenceIdentifier('100000001-2');

    $report = makeExportService(['key' => $api])->createConcepts([$colloOne, $colloTwo]);

    expect($report->succeeded())->toHaveKey('100000001')
        ->and($report->hasFailures())->toBeTrue()
        ->and($report->failed()['100000001'])->toContain('did not return a shipment');
});

it('exposes twenty as the default chunk size', function () {
    expect(ShipmentExportService::DEFAULT_CHUNK_SIZE)->toBe(20);
});
