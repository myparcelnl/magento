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
        /** @var array<int,array{0: string, 1: mixed}> every where() of the outer select, with its bound value */
        public array $bindings = [];
        /** @var array<int,array{0: string, 1: mixed}> the same for the EXISTS subselects */
        public array $subBindings = [];
    };

    $select = Mockery::mock(Magento\Framework\DB\Select::class);
    $select->shouldReceive('where')->andReturnUsing(static function (...$args) use ($select, $captured) {
        $captured->wheres[]   = (string) $args[0];
        $captured->bindings[] = [(string) $args[0], $args[1] ?? null];

        return $select;
    });

    $subSelect = Mockery::mock(Magento\Framework\DB\Select::class);
    $subSelect->shouldReceive('from')->andReturnSelf();
    $subSelect->shouldReceive('where')->andReturnUsing(static function (...$args) use ($subSelect, $captured) {
        $captured->subBindings[] = [(string) $args[0], $args[1] ?? null];

        return $subSelect;
    });
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

    return [
        'filters'     => $captured->filters,
        'wheres'      => $captured->wheres,
        'bindings'    => $captured->bindings,
        'subBindings' => $captured->subBindings,
    ];
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
    // Asserted through the query, not by restating the constant: a selector that stopped binding
    // the placeholders would call a printed-but-barcodeless order done and never look again.
    $bound = array_merge(capturedSelection()['bindings'], capturedSelection()['subBindings']);
    $values = array_filter(array_column($bound, 1), 'is_array');

    expect($values)->toContain(TrackAndTrace::PLACEHOLDERS);
});

it('only counts this module carrier tracks', function () {
    // Another carrier's barcode on the same order says nothing about the MyParcel shipment.
    $bound = array_merge(capturedSelection()['bindings'], capturedSelection()['subBindings']);

    expect(array_column($bound, 1))->toContain(Carrier::CODE);
});

/**
 * Runs execute() with nothing awaiting a barcode, so the PPS pass returns at once and only the
 * status poll can reach the collection.
 *
 * @return object the recorder: whether updateMagentoTrack() was reached
 */
function runCronInMode(string $exportMode): object
{
    $logger = mockLoggerFacade();
    $logger->shouldReceive('debug', 'notice', 'warning')->andReturnNull();

    $reached = new class {
        public bool $polled = false;
    };

    $fluent = static function (string $class, array $methods, array $answers = []) {
        $mock = Mockery::mock($class);

        foreach ($methods as $method) {
            $mock->shouldReceive($method)->andReturnSelf();
        }

        foreach ($answers as $method => $answer) {
            $mock->shouldReceive($method)->andReturn($answer);
        }

        return $mock;
    };

    $objectManager = Mockery::mock(Magento\Framework\ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')
        ->with(MyParcelNL\Magento\Service\Export\ShipmentApiProvider::class)
        ->andReturn(Mockery::mock(MyParcelNL\Magento\Service\Export\ShipmentApiProvider::class));
    $objectManager->shouldReceive('get')
        ->with(UpdateStatus::PATH_MODEL_ORDER_TRACK)
        ->andReturn($fluent(
            Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection::class,
            ['addFieldToSelect', 'addAttributeToFilter', 'setPageSize', 'setOrder'],
            ['getData' => []]
        ));
    $objectManager->shouldReceive('create')
        ->with(MagentoCollection::PATH_MODEL_ORDER_COLLECTION)
        ->andReturn($fluent(
            Magento\Sales\Model\ResourceModel\Order\Collection::class,
            ['addAttributeToFilter', 'addFieldToFilter']
        ));

    $orderCollection = Mockery::mock(MyParcelNL\Magento\Model\Sales\MagentoOrderCollection::class);
    $orderCollection->shouldReceive('setOrderCollection')->andReturnSelf();
    $orderCollection->shouldReceive('updateMagentoTrack')->andReturnUsing(
        static function () use ($orderCollection, $reached) {
            $reached->polled = true;

            return $orderCollection;
        }
    );

    $config = Mockery::mock(MyParcelNL\Magento\Service\Config::class);
    $config->shouldReceive('getExportMode')->andReturn($exportMode);

    $cron = newInstanceWithoutConstructor(MyParcelNL\Magento\Tests\Stub\RecordingUpdateStatus::class);
    setPrivateProperty($cron, 'objectManager', $objectManager);
    setPrivateProperty($cron, 'orderCollection', $orderCollection);
    setPrivateProperty($cron, 'config', $config);
    $cron->orderRows = [];

    $cron->execute();

    return $reached;
}

it('polls shipment statuses in shipments mode', function () {
    expect(runCronInMode('shipments')->polled)->toBeTrue();
});

it('polls shipment statuses in PPS mode as well', function () {
    // PPS acquires barcodes and drops an order the moment it has one, so without this pass a PPS
    // order's myparcel_status stays at whatever its barcode pass wrote, for good.
    expect(runCronInMode(MyParcelNL\Magento\Service\Config::EXPORT_MODE_PPS)->polled)->toBeTrue();
});
