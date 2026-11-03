<?php

declare(strict_types=1);

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelNL\Magento\Setup\Migrations\RenameMyParcelShipmentIdColumn;

/**
 * The track column takes the code's name. It runs on every upgrade below 5.11.0, also when an earlier
 * run already renamed it, so the guards are what keep a second run from failing.
 *
 * @param string[] $columns the columns sales_shipment_track has before the run
 *
 * @return array<int,array{0: string, 1: string, 2: string, 3: array}> the changeColumn() calls
 */
function runRenameShipmentIdColumn(array $columns, bool $tableExists = true): array
{
    $calls = [];

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('isTableExists')->with('sales_shipment_track')->andReturn($tableExists);
    $connection->shouldReceive('tableColumnExists')->andReturnUsing(
        static fn(string $table, string $column): bool => in_array($column, $columns, true)
    );
    $connection->shouldReceive('changeColumn')->andReturnUsing(
        static function (...$args) use (&$calls) {
            $calls[] = $args;

            return null;
        }
    );

    $setup = Mockery::mock(SchemaSetupInterface::class);
    $setup->shouldReceive('getConnection')->andReturn($connection);
    $setup->shouldReceive('getTable')->andReturnUsing(static fn(string $name): string => $name);

    (new RenameMyParcelShipmentIdColumn())->run($setup);

    return $calls;
}

it('renames the column with its integer definition kept', function () {
    $calls = runRenameShipmentIdColumn(['entity_id', 'myparcel_consignment_id', 'myparcel_status']);

    expect($calls)->toHaveCount(1)
        ->and($calls[0][0])->toBe('sales_shipment_track')
        ->and($calls[0][1])->toBe('myparcel_consignment_id')
        ->and($calls[0][2])->toBe('myparcel_shipment_id')
        ->and($calls[0][3])->toBe(['type' => Table::TYPE_INTEGER, 'nullable' => true, 'comment' => 'MyParcel shipment id']);
});

it('leaves a table that already has the new column alone', function () {
    expect(runRenameShipmentIdColumn(['myparcel_consignment_id', 'myparcel_shipment_id']))->toBe([]);
});

it('does nothing when the old column is already gone', function () {
    expect(runRenameShipmentIdColumn(['myparcel_shipment_id']))->toBe([]);
});

it('does nothing without the track table', function () {
    expect(runRenameShipmentIdColumn([], false))->toBe([]);
});
