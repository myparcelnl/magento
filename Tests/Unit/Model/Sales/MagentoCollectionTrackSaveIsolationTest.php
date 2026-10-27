<?php

declare(strict_types=1);

/**
 * One unsaveable track must not end the run.
 *
 * The status cron selects on the track's own status, excludes nothing that failed before, and sorts
 * entity_id DESC. A row that throws on save gets the same place next tick, and every order behind it
 * stalls while the row stays broken.
 *
 * partialOrderCollection(), recordingExportService(), myParcelShipmentIdsStub() and apiShipment() live
 * in Tests/Helpers/UpdateMagentoTrackFixtures.php; recordingTrack() in Tests/Helpers/ExportFixtures.php.
 */

/**
 * A collection over one shipment carrying two tracks, the first of which refuses to save.
 *
 * @return array{0: MyParcelNL\Magento\Model\Sales\MagentoOrderCollection, 1: object}
 */
function collectionWhoseFirstTrackCannotSave(): array
{
    $saved = new class {
        public array $saves   = [];
        public array $fetched = [];
    };

    $track = static function (int $myParcelShipmentId, bool $throws) use ($saved) {
        return recordingTrack(
            [
                'setTrackNumber' => static function (): void {
                },
                'setData'        => static function (): void {
                },
                'save'           => static function () use ($saved, $myParcelShipmentId, $throws): void {
                    if ($throws) {
                        throw new Exception('constraint violation on this row');
                    }

                    $saved->saves[] = $myParcelShipmentId;
                },
            ],
            [
                'getData' => static function (?string $key = null) use ($myParcelShipmentId) {
                    return 'myparcel_shipment_id' === $key ? $myParcelShipmentId : null;
                },
            ]
        );
    };

    $shipment   = createShipment();
    $collection = partialOrderCollection([$shipment]);

    $collection->shouldReceive('tracksByShipmentId')
               ->andReturn([(int) $shipment->getId() => [$track(4242, true), $track(4243, false)]]);
    $collection->shouldReceive('getMyParcelShipmentIdsByApiKey')
               ->andReturnUsing(myParcelShipmentIdsStub([4242, 4243]));
    $collection->shouldReceive('updateOrderGrid')->andReturnSelf();

    setPrivateProperty($collection, 'exportService', recordingExportService([
        4242 => apiShipment(4242, '3SMYPA111', null),
        4243 => apiShipment(4243, '3SMYPA222', null),
    ], $saved));

    return [$collection, $saved];
}

it('carries on to the next track when one cannot be saved', function () {
    mockLoggerFacade()->shouldReceive('warning')->byDefault();

    [$collection, $saved] = collectionWhoseFirstTrackCannotSave();

    $collection->updateMagentoTrack();

    expect($saved->saves)->toBe([4243]);
});

it('says which run lost a track rather than failing silently', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with('MyParcel: could not update one track', Mockery::any());

    [$collection] = collectionWhoseFirstTrackCannotSave();

    $collection->updateMagentoTrack();
});

it('carries on to the next collo when the track of one collo cannot be saved', function () {
    mockLoggerFacade()->shouldReceive('warning')->once()->with('MyParcel: could not save the track of one collo', Mockery::any());

    [$collection, $saved] = collectionRefreshing(4242, [
        4242 => apiShipmentWithColli(4242, '3SMAIN', [apiCollo(4243, '3SCOLLO2'), apiCollo(4244, '3SCOLLO3')]),
    ]);

    // The first collo's new track is made but refuses to save; the second saves.
    $made  = 0;
    $saves = [];
    $collection->shouldReceive('newMagentoTrack')->andReturnUsing(static function () use (&$made, &$saves, $saved) {
        $number = ++$made;
        $key    = array_push($saved->created, ['number' => null, 'data' => []]) - 1;

        return recordingTrack([
            'setTrackNumber' => static function (): void {
            },
            'setData'        => static function ($field, $value) use ($saved, $key): void {
                $saved->created[$key]['data'][$field] = $value;
            },
            'save'           => static function () use ($number, $saved, $key, &$saves): void {
                if (1 === $number) {
                    throw new Exception('constraint violation on this row');
                }

                $saves[] = $saved->created[$key]['data']['myparcel_shipment_id'] ?? null;
            },
        ]);
    });

    $collection->updateMagentoTrack();

    expect($saved->created)->toHaveCount(2)
        ->and($saves)->toBe([4244]);
});
