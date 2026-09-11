<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Sales\MagentoCollection;
use MyParcelNL\Magento\Cron\UpdateStatus;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

/**
 * The selector decides which orders the cron can ever see, and getting it wrong is what locked
 * every exported order out. These assert the conditions it puts on the query rather than
 * its SQL text — what matters is which facts it keys on, not how they are spelled.
 *
 * @return array{filters: array, wheres: string[]}
 */
function capturedSelection(): array
{
    $captured = new class {
        public array $filters = [];
        public array $wheres  = [];
    };

    $select = Mockery::mock(Magento\Framework\DB\Select::class);
    $select->shouldReceive('where')->andReturnUsing(static function (...$args) use ($select, $captured) {
        $captured->wheres[] = (string) $args[0];

        return $select;
    });

    $subSelect = Mockery::mock(Magento\Framework\DB\Select::class);
    foreach (['from', 'where'] as $fluent) {
        $subSelect->shouldReceive($fluent)->andReturnSelf();
    }
    $subSelect->shouldReceive('__toString')->andReturn('SELECT 1');

    $connection = Mockery::mock(Magento\Framework\DB\Adapter\AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($subSelect);

    $orders = Mockery::mock(Magento\Sales\Model\ResourceModel\Order\Collection::class);
    foreach (['addFieldToSelect', 'setPageSize', 'setOrder'] as $fluent) {
        $orders->shouldReceive($fluent)->andReturnSelf();
    }
    $orders->shouldReceive('addFieldToFilter')->andReturnUsing(
        static function (string $field, $condition) use ($orders, $captured) {
            $captured->filters[$field] = $condition;

            return $orders;
        }
    );
    $orders->shouldReceive('getConnection')->andReturn($connection);
    $orders->shouldReceive('getTable')->andReturnUsing(static fn(string $t): string => $t);
    $orders->shouldReceive('getSelect')->andReturn($select);
    $orders->shouldReceive('getData')->andReturn([]);

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')
        ->with(MagentoCollection::PATH_MODEL_ORDER_COLLECTION)
        ->andReturn($orders);

    // UpdateStatus itself, not the recording stub: that stub overrides this very method.
    $cron = newInstanceWithoutConstructor(UpdateStatus::class);
    setPrivateProperty($cron, 'objectManager', $objectManager);

    invokePrivateMethod($cron, 'ordersAwaitingBarcode');

    return ['filters' => $captured->filters, 'wheres' => $captured->wheres];
}

it('selects on the export marker, not on a grid display column', function () {
    // myparcel_uuid is what says the order reached MyParcel. track_status was the old marker and
    // it is a translated display value.
    expect(capturedSelection()['filters'])->toHaveKey('myparcel_uuid')
        ->and(capturedSelection()['filters']['myparcel_uuid'])->toBe(['notnull' => true]);
});

it('bounds the window to what addOrdersToCollection can still load', function () {
    // Selecting orders the second query then drops by created_at would poll them forever.
    expect(capturedSelection()['filters'])->toHaveKey('created_at')
        ->and(array_keys(capturedSelection()['filters']['created_at']))->toBe(['gteq']);
});

it('excludes an order that already has a real barcode on a track', function () {
    $wheres = implode(' ', capturedSelection()['wheres']);

    expect($wheres)->toContain('NOT EXISTS');
});

it('still selects an order whose grid column holds only a placeholder', function () {
    // The rows the old code stamped '-' onto must come back, or every install needs a data fix
    // before the cron works again.
    $wheres = implode(' ', capturedSelection()['wheres']);

    expect($wheres)->toContain('main_table.track_number IS NULL')
        ->and($wheres)->toContain('main_table.track_number IN');
});

it('lets a barcode on the order row settle it only for an order with no track', function () {
    // The four orders that were shipped in Magento had a real barcode on the order row and a
    // placeholder on the track. Reading the order row alone called them done and they never came
    // back — the same mistake as the original bug, one level up.
    $wheres = implode(' ', capturedSelection()['wheres']);

    expect($wheres)->toContain('EXISTS')
        ->and(substr_count($wheres, 'EXISTS'))->toBeGreaterThanOrEqual(2);
});

it('treats printed as still waiting, because no barcode arrived', function () {
    expect(TrackAndTrace::PLACEHOLDERS)->toBe([TrackAndTrace::VALUE_EMPTY, TrackAndTrace::VALUE_PRINTED]);
});

it('only counts this module carrier tracks', function () {
    // Another carrier's barcode on the same order says nothing about the MyParcel shipment.
    expect(Carrier::CODE)->toBe('myparcel');
});
