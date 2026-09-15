<?php

declare(strict_types=1);

/**
 * One unsaveable track must not end the run.
 *
 * The status cron selects on the track's own status and excludes nothing that failed before, and it
 * sorts entity_id DESC — so a row that throws on save wins the same place next tick, and every
 * order behind it stalls for as long as the row stays broken.
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
