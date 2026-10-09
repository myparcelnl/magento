<?php

declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MyParcelNL\Magento\Setup\Migrations\ShipmentOptionsFalseToNull;
use Psr\Log\NullLogger;

/**
 * A stored false used to mean 'not ticked' and now means 'off'. The migration rewrites what the
 * checkout stored before, so no placed order loses its configured defaults.
 *
 * @param array<string,array<int,array{entity_id:int,myparcel_delivery_options:string}>> $rowsByTable
 */
function runShipmentOptionsFalseToNull(array $rowsByTable): object
{
    $did = new class {
        public array $selects = [];
        /** @var array<int,array{table:string,blob:string,where:array}> */
        public array $updates = [];
    };

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('fetchAll')->andReturnUsing(
        static function (string $sql, array $bind) use ($did, $rowsByTable): array {
            $did->selects[] = $sql;
            $table          = preg_match('/FROM (\w+)/', $sql, $m) ? $m[1] : '';

            return array_values(array_filter(
                $rowsByTable[$table] ?? [],
                static fn(array $row): bool => $row['entity_id'] > $bind['last']
            ));
        }
    );
    $connection->shouldReceive('update')->andReturnUsing(
        static function (string $table, array $data, array $where) use ($did): int {
            $did->updates[] = ['table' => $table, 'blob' => $data['myparcel_delivery_options'], 'where' => $where];

            return 1;
        }
    );

    $connection->shouldReceive('beginTransaction', 'commit')->andReturnSelf();

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $name): string => $name);

    (new ShipmentOptionsFalseToNull($resource, new NullLogger()))->run();

    return $did;
}

function storedBlob(array $shipmentOptions): string
{
    return json_encode(['carrier' => 'postnl', 'deliveryType' => 'standard', 'shipmentOptions' => $shipmentOptions]);
}

it('rewrites a stored false to null on orders and active quotes, and keeps true', function () {
    $did = runShipmentOptionsFalseToNull([
        'sales_order' => [['entity_id' => 4, 'myparcel_delivery_options' => storedBlob(['signature' => false, 'only_recipient' => true])]],
        'quote'       => [['entity_id' => 9, 'myparcel_delivery_options' => storedBlob(['age_check' => false])]],
    ]);

    expect($did->updates)->toHaveCount(2)
        ->and(json_decode($did->updates[0]['blob'], true)['shipmentOptions'])->toBe(['signature' => null, 'only_recipient' => true])
        ->and($did->updates[0]['where'])->toBe(['entity_id = ?' => 4])
        ->and($did->updates[1]['table'])->toBe('quote');
});

it('only reads active quotes', function () {
    $did = runShipmentOptionsFalseToNull([]);

    expect(implode("\n", array_filter($did->selects, static fn(string $sql): bool => false !== strpos($sql, 'FROM quote'))))
        ->toContain('is_active = 1');
});

it('leaves a row it cannot decode, or that has nothing to change, untouched', function () {
    $did = runShipmentOptionsFalseToNull([
        'sales_order' => [
            ['entity_id' => 1, 'myparcel_delivery_options' => '{not json false'],
            ['entity_id' => 2, 'myparcel_delivery_options' => json_encode(['deliveryType' => 'standard', 'isPickup' => false])],
        ],
    ]);

    expect($did->updates)->toBe([]);
});

it('is idempotent', function () {
    $migrated = storedBlob(['signature' => null]);

    expect(runShipmentOptionsFalseToNull(['sales_order' => [['entity_id' => 1, 'myparcel_delivery_options' => $migrated]]])->updates)
        ->toBe([]);
});
