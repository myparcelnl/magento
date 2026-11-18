<?php

declare(strict_types=1);

/**
 * One unsaveable track must not end the run.
 *
 * The status cron selects on the track's own status, excludes nothing that failed before, and sorts
 * entity_id DESC. A row that throws on save gets the same place next tick, and every order behind it
 * stalls while the row stays broken.
 *
 * partialOrderCollection(), recordingExportService(), consignmentIdsStub() and apiShipment() live
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

    $track = static function (int $consignmentId, bool $throws) use ($saved) {
        return recordingTrack(
            [
                'setTrackNumber' => static function (): void {
                },
                'setData'        => static function (): void {
                },
                'save'           => static function () use ($saved, $consignmentId, $throws): void {
                    if ($throws) {
                        throw new Exception('constraint violation on this row');
                    }

                    $saved->saves[] = $consignmentId;
                },
            ],
            [
                'getData' => static function (?string $key = null) use ($consignmentId) {
                    return 'myparcel_consignment_id' === $key ? $consignmentId : null;
                },
            ]
        );
    };

    $shipment   = createShipment();
    $collection = partialOrderCollection([$shipment]);

    $collection->shouldReceive('tracksByShipmentId')
               ->andReturn([(int) $shipment->getId() => [$track(4242, true), $track(4243, false)]]);
    $collection->shouldReceive('getMyparcelConsignmentIdsByApiKey')
               ->andReturnUsing(consignmentIdsStub([4242, 4243]));
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

    $made = 0;
    $collection->shouldReceive('setNewMagentoTrack')->andReturnUsing(static function () use (&$made, $saved) {
        if (1 === ++$made) {
            throw new Exception('constraint violation on this row');
        }

        $saved->created[] = ['number' => null, 'data' => []];

        return recordingTrack([
            'setTrackNumber' => static function (): void {
            },
            'setData'        => static function ($field, $value) use ($saved): void {
                $saved->created[array_key_last($saved->created)]['data'][$field] = $value;
            },
        ]);
    });

    $collection->updateMagentoTrack();

    expect($saved->created)->toHaveCount(1)
        ->and($saved->created[0]['data']['myparcel_consignment_id'])->toBe(4244);
});
