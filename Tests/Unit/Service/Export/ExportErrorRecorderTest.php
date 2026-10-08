<?php

declare(strict_types=1);

use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Model\Shipment\BuiltShipment;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Export\ExportErrorRecorder;
use MyParcelNL\Magento\Service\Export\ExportReport;
use MyParcelNL\Magento\Service\OrderGridColumns;
use MyParcelNL\Sdk\Model\Shipment\Shipment as SdkShipment;

/**
 * The refusal reason outlives the flash message: it is kept on the order and its grid row until an
 * export of that order succeeds.
 *
 * @param array<int,string> $previous the error each order carried before, by entity id
 *
 * @return object{updates: array<int,array{error:?string,id:int}>, orders: array<string,object>}
 */
function recordExport(ExportReport $report, array $incrementIds, array $previous = []): object
{
    $did = new class {
        public array $updates = [];
        public array $orders  = [];
    };

    $gridColumns = Mockery::mock(OrderGridColumns::class);
    $gridColumns->shouldReceive('update')->andReturnUsing(static function (int $id, array $columns) use ($did): void {
        $did->updates[] = ['error' => $columns[Config::FIELD_EXPORT_ERROR], 'id' => $id];
    });

    $built = [];

    foreach ($incrementIds as $entityId => $incrementId) {
        $order = createOrder(['getId' => $entityId, 'getIncrementId' => $incrementId]);
        $order->shouldReceive('getData')->with(Config::FIELD_EXPORT_ERROR)->andReturn($previous[$entityId] ?? null);
        $order->written = [];
        $order->shouldReceive('setData')->andReturnUsing(static function (string $key, $value) use ($order) {
            $order->written[$key] = $value;

            return $order;
        });
        $did->orders[$incrementId] = $order;

        $shipment = Mockery::mock(Shipment::class);
        $shipment->shouldReceive('getOrder')->andReturn($order);
        $track = Mockery::mock(Track::class);
        $track->shouldReceive('getShipment')->andReturn($shipment);

        $built[] = new BuiltShipment(new SdkShipment(), $track, 'key', $incrementId);
    }

    (new ExportErrorRecorder($gridColumns))->record($report, $built);

    return $did;
}

it('keeps the reasons of a blamed order on the order and its grid row', function () {
    $report = new ExportReport();
    $report->fail('100000007', 'signature is not allowed');
    $report->fail('100000007', 'insurance too high');

    $did = recordExport($report, [7 => '100000007']);

    expect($did->updates)->toBe([
        ['error' => 'signature is not allowed; insurance too high', 'id' => 7],
    ])->and($did->orders['100000007']->written[Config::FIELD_EXPORT_ERROR])->toBe('signature is not allowed; insurance too high');
});

it('clears the error of an order that shipped, and leaves collateral alone', function () {
    $report = new ExportReport();
    $report->succeed('100000001', 55);
    $report->failCollateral('100000002', 'another order was refused');

    $did = recordExport($report, [1 => '100000001', 2 => '100000002'], [1 => 'old', 2 => 'old']);

    expect($did->updates)->toBe([['error' => null, 'id' => 1]]);
});

it('writes nothing for an order whose error is already what the export says', function () {
    $report = new ExportReport();
    $report->succeed('100000001', 55);
    $report->fail('100000002', 'same as before');

    $did = recordExport($report, [1 => '100000001', 2 => '100000002'], [2 => 'same as before']);

    expect($did->updates)->toBe([]);
});

it('cuts a long refusal to the column limit', function () {
    $report = new ExportReport();
    $report->fail('100000007', str_repeat('x', 1500));

    expect(mb_strlen(recordExport($report, [7 => '100000007'])->updates[0]['error']))->toBe(1000);
});
