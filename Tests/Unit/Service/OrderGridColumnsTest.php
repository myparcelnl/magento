<?php

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\ResourceModel\Grid;
use MyParcelNL\Magento\Service\OrderGridColumns;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;
use MyParcelNL\Magento\Model\Carrier\Carrier;

/**
 * The two grid columns for a page of orders: one tracks query, one read of the current values, a
 * column-level write for every order whose values changed, and Magento's own grid refresh for each
 * of those. The full $order->save() this replaces wrote every column of a possibly stale order and
 * re-synced the grid once per shipment.
 *
 * @param array<int,array<string,mixed>> $tracks  sales_shipment_track rows, as fetchAll() returns them
 * @param array<int,array<string,mixed>> $current sales_order rows: entity_id, track_status, track_number
 *
 * @return array{0: OrderGridColumns, 1: object} the service and a recorder of what it did
 */
function gridColumnsWriter(array $tracks, array $current, bool $async = false, ?string $areaCode = 'adminhtml'): array
{
    $did = new class {
        public int   $fetches        = 0;
        public array $updates        = [];
        public array $refreshed      = [];
        public int   $translateLoads = 0;
        public array $wheres         = [];
    };

    $select = Mockery::mock(Select::class);
    $select->shouldReceive('from', 'order')->andReturnSelf();
    $select->shouldReceive('where')->andReturnUsing(static function (...$args) use ($select, $did) {
        $did->wheres[] = $args;

        return $select;
    });

    $fetchResults = [$tracks, $current];
    $connection   = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('fetchAll')->andReturnUsing(static function () use (&$fetchResults, $did) {
        $did->fetches++;

        return array_shift($fetchResults) ?? [];
    });
    $connection->shouldReceive('update')->andReturnUsing(static function (string $table, array $bind, array $where) use ($did) {
        $did->updates[] = ['table' => $table, 'bind' => $bind, 'where' => $where];

        return 1;
    });

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $table): string => $table);

    $grid = Mockery::mock(Grid::class);
    $grid->shouldReceive('refresh')->andReturnUsing(static function ($orderId) use ($did) {
        $did->refreshed[] = $orderId;
    });

    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->with('dev/grid/async_indexing')->andReturn($async ? '1' : '0');

    $area = Mockery::mock(Area::class);
    $area->shouldReceive('load')->with(Area::PART_TRANSLATE)->andReturnUsing(static function () use ($did, $area) {
        $did->translateLoads++;

        return $area;
    });
    $areaList = Mockery::mock(AreaList::class);
    $areaList->shouldReceive('getArea')->with(Area::AREA_ADMINHTML)->andReturn($area);

    $state = Mockery::mock(State::class);
    if (null === $areaCode) {
        $state->shouldReceive('getAreaCode')->andThrow(new LocalizedException(__('Area code is not set')));
    } else {
        $state->shouldReceive('getAreaCode')->andReturn($areaCode);
    }

    return [new OrderGridColumns($resource, $grid, $scopeConfig, $areaList, $state), $did];
}

/** @return array<string,mixed> */
function trackRowFor(int $orderId, string $number, ?int $status = 3): array
{
    return ['entity_id' => crc32($orderId . $number), 'order_id' => $orderId, 'track_number' => $number, 'myparcel_status' => $status];
}

/** @return array<string,mixed> */
function orderRow(int $orderId, string $status = '', string $number = ''): array
{
    return ['entity_id' => $orderId, 'track_status' => $status, 'track_number' => $number];
}

it('reads the tracks and the current columns once each, and writes each order once', function () {
    [$columns, $did] = gridColumnsWriter(
        [trackRowFor(7, '3SA'), trackRowFor(7, '3SB'), trackRowFor(9, '3SC')],
        [orderRow(7), orderRow(9)]
    );

    $written = $columns->writeFor([7, 7, 9]);

    expect($written)->toBe(2)
        ->and($did->fetches)->toBe(2)
        ->and(array_column($did->updates, 'where'))->toBe([['entity_id = ?' => 7], ['entity_id = ?' => 9]])
        ->and($did->updates[0]['table'])->toBe('sales_order')
        ->and($did->updates[0]['bind']['track_number'])->toBe('["3SA","3SB"]')
        ->and($did->updates[0]['bind']['track_status'])->toBe((string) __('status_3') . '<br>' . (string) __('status_3'))
        ->and($did->updates[1]['bind']['track_number'])->toBe('["3SC"]');
});

it('skips an order whose columns already hold the computed values', function () {
    [$columns, $did] = gridColumnsWriter(
        [trackRowFor(7, '3SA'), trackRowFor(9, '3SC')],
        [orderRow(7), orderRow(9, (string) __('status_3'), '["3SC"]')]
    );

    expect($columns->writeFor([7, 9]))->toBe(1)
        ->and(array_column($did->updates, 'where'))->toBe([['entity_id = ?' => 7]])
        ->and($did->refreshed)->toBe([7]);
});

it('refreshes the grid row of each written order when async indexing is off', function () {
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA'), trackRowFor(9, '3SC')], [orderRow(7), orderRow(9)]);

    $columns->writeFor([7, 9]);

    expect($did->refreshed)->toBe([7, 9]);
});

it('leaves the grid to the scheduled sync when async indexing is on, and stamps updated_at', function () {
    // Magento's own observer skips the synchronous refresh in this mode; refreshBySchedule() then
    // picks the row up by updated_at, which a full order save used to bump as a side effect.
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA')], [orderRow(7)], true);

    $columns->writeFor([7]);

    expect($did->refreshed)->toBe([])
        ->and($did->updates[0]['bind']['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});

it('loads the admin translations once per call outside adminhtml, whatever the page size', function () {
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA'), trackRowFor(9, '3SC')], [orderRow(7), orderRow(9)], false, 'crontab');

    $columns->writeFor([7, 9]);

    expect($did->translateLoads)->toBe(1);
});

it('treats an unset area code as outside adminhtml', function () {
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA')], [orderRow(7)], false, null);

    $columns->writeFor([7]);

    expect($did->translateLoads)->toBe(1);
});

it('does not touch the translations in adminhtml', function () {
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA')], [orderRow(7)]);

    $columns->writeFor([7]);

    expect($did->translateLoads)->toBe(0);
});

it('never blanks a column the tracks cannot fill', function () {
    // A placeholder is not a track number and a track without a status says nothing, so neither
    // column is written; the values the row already holds stay.
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '–', null)], [orderRow(7, 'Exported', '["3SA"]')]);

    expect($columns->writeFor([7]))->toBe(0)
        ->and($did->updates)->toBe([])
        ->and($did->refreshed)->toBe([]);
});

it('writes nothing and reads nothing for no orders', function () {
    [$columns, $did] = gridColumnsWriter([], []);

    expect($columns->writeFor([]))->toBe(0)
        ->and($did->fetches)->toBe(0);
});

it('does not treat a placeholder as a track number', function () {
    [$columns] = gridColumnsWriter([], []);

    $html = $columns->htmlForTracks([
        ['track_number' => TrackAndTrace::VALUE_EMPTY, 'myparcel_status' => null],
        ['track_number' => TrackAndTrace::VALUE_PRINTED, 'myparcel_status' => null],
    ]);

    expect($html['track_number'])->toBe('');
});

it('ignores a track belonging to another carrier', function () {
    // The shipment observer hands over $shipment->getTracksCollection(), which carries every
    // carrier's rows. A manually added DHL barcode reaching sales_order.track_number would then be
    // offered a MyParcel portal link that portal never issued.
    [$columns] = gridColumnsWriter([], []);

    $html = $columns->htmlForTracks([
        ['track_number' => 'DHL-999', 'myparcel_status' => 7, 'carrier_code' => 'dhl'],
        ['track_number' => '3SMYPA123', 'myparcel_status' => null, 'carrier_code' => Carrier::CODE],
    ]);

    expect($html['track_number'])->toBe(json_encode(['3SMYPA123']))
        ->and($html['track_status'])->toBe('');
});

it('keeps a track that names no carrier, which is its own not-yet-saved row', function () {
    // The observer's tracks are built before carrier_code is set on them. Dropping those would
    // blank the barcode the same pass just minted.
    [$columns] = gridColumnsWriter([], []);

    $html = $columns->htmlForTracks([['track_number' => '3SMYPA123', 'myparcel_status' => null]]);

    expect($html['track_number'])->toBe(json_encode(['3SMYPA123']));
});

it('still reports a real barcode alongside a placeholder', function () {
    [$columns] = gridColumnsWriter([], []);

    $html = $columns->htmlForTracks([
        ['track_number' => '3SMYPA123', 'myparcel_status' => null],
        ['track_number' => TrackAndTrace::VALUE_EMPTY, 'myparcel_status' => null],
    ]);

    expect($html['track_number'])->toBe(json_encode(['3SMYPA123']));
});

/**
 * writeColumns() is the same write for a caller that already holds its tracks — the shipment
 * observer, whose tracks have no entity id yet and so cannot be queried. It reads only the current
 * values, which is the fixture's first fetch.
 */
it('writes the columns a caller hands it, without querying the tracks', function () {
    [$columns, $did] = gridColumnsWriter([orderRow(7)], []);

    $written = $columns->writeColumns(7, ['track_status' => 'status_3', 'track_number' => '["3SA"]']);

    expect($written)->toBeTrue()
        ->and($did->fetches)->toBe(1)
        ->and($did->updates[0]['bind']['track_status'])->toBe('status_3')
        ->and($did->updates[0]['bind']['track_number'])->toBe('["3SA"]')
        ->and($did->refreshed)->toBe([7]);
});

it('writes nothing when the columns it is handed match what is stored', function () {
    [$columns, $did] = gridColumnsWriter([orderRow(7, 'status_3', '["3SA"]')], []);

    $written = $columns->writeColumns(7, ['track_status' => 'status_3', 'track_number' => '']);

    expect($written)->toBeFalse()
        ->and($did->updates)->toBe([])
        ->and($did->refreshed)->toBe([]);
});

it('leaves a stored track number alone when the caller has none to offer', function () {
    // The same rule writeFor() follows: a column the tracks cannot fill is never blanked.
    [$columns, $did] = gridColumnsWriter([orderRow(7, '', '["3SA"]')], []);

    $columns->writeColumns(7, ['track_status' => 'status_3', 'track_number' => '']);

    expect($did->updates[0]['bind'])->toHaveKey('track_status')
        ->and($did->updates[0]['bind'])->not->toHaveKey('track_number');
});

/**
 * The grid's own track query was the one place the MyParcel restriction was never applied, so a
 * manual track of another carrier reached sales_order.track_number.
 */
it('asks only for myparcel tracks', function () {
    [$columns, $did] = gridColumnsWriter([trackRowFor(7, '3SA')], [orderRow(7)]);

    $columns->writeFor([7]);

    $carrierClauses = array_values(array_filter(
        $did->wheres,
        static fn(array $args): bool => false !== strpos((string) ($args[0] ?? ''), 'carrier_code')
    ));

    expect($carrierClauses)->not->toBeEmpty()
        ->and($carrierClauses[0][1])->toBe(Carrier::CODE);
});
