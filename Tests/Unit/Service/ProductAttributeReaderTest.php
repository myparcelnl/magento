<?php

declare(strict_types=1);

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MyParcelNL\Magento\Service\ProductAttributeReader;

/**
 * ShipmentOptionsResolver and PackageRepository each had their own copy of these queries, and the
 * copies had already drifted. What is asserted here is what both of them relied on.
 *
 * @param array<string,mixed> $answers fetchOne() answers, keyed by table
 * @param array<int,array>    $queries filled by reference, one entry per fetchOne()
 */
function attributeReaderFor(array $answers, ?array &$queries): ProductAttributeReader
{
    $queries    = [];
    $connection = Mockery::mock(AdapterInterface::class);

    $connection->shouldReceive('select')->andReturnUsing(static function () {
        $select = Mockery::mock();
        $select->recordedWheres = [];

        $select->shouldReceive('from')->andReturnUsing(static function ($table, $columns = null) use ($select) {
            $select->recordedTable   = is_array($table) ? reset($table) : $table;
            $select->recordedColumns = $columns;

            return $select;
        });
        $select->shouldReceive('where')->andReturnUsing(static function ($clause, $value = null) use ($select) {
            $select->recordedWheres[] = [$clause, $value];

            return $select;
        });

        return $select;
    });

    $connection->shouldReceive('fetchOne')->andReturnUsing(
        static function ($select) use ($answers, &$queries) {
            $queries[] = [
                'table'   => $select->recordedTable,
                'columns' => $select->recordedColumns,
                'wheres'  => $select->recordedWheres,
            ];

            return $answers[$select->recordedTable] ?? false;
        }
    );

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $name): string => $name);

    return new ProductAttributeReader($resource);
}

/** The answers a fully-populated install would give. */
function attributeReaderAnswers(array $extra = []): array
{
    return array_merge(['eav_entity_type' => '4', 'eav_attribute' => '137'], $extra);
}

it('asks for the attribute id by name, not by column position', function () {
    // Both old copies passed 'entity_type_id' to select(), which takes no arguments — so the query
    // was SELECT * and fetchOne() returned the first column. It was attribute_id, by luck.
    $queries = [];

    attributeReaderFor(attributeReaderAnswers(), $queries)->attributeId('classification');

    $attributeQuery = $queries[1];

    expect($attributeQuery['table'])->toBe('eav_attribute')
        ->and($attributeQuery['columns'])->toBe(['attribute_id']);
});

it('prefixes the column and scopes the lookup to catalog_product', function () {
    // Without the entity-type condition an attribute of the same code on another entity type could
    // win the row, which is a wrong answer rather than an error.
    $queries = [];

    attributeReaderFor(attributeReaderAnswers(), $queries)->attributeId('age_check');

    expect($queries[0]['table'])->toBe('eav_entity_type')
        ->and($queries[0]['wheres'])->toBe([['entity_type_code = ?', 'catalog_product']])
        ->and($queries[1]['wheres'])->toBe([
            ['attribute_code = ?', 'myparcel_age_check'],
            ['entity_type_id = ?', '4'],
        ]);
});

it('reads the value by attribute and entity id', function () {
    $queries = [];

    $value = attributeReaderFor(
        attributeReaderAnswers(['catalog_product_entity_varchar' => '6109.10']),
        $queries
    )->value('catalog_product_entity_varchar', '99', 'classification');

    expect($value)->toBe('6109.10')
        ->and($queries[2]['columns'])->toBe(['value'])
        ->and($queries[2]['wheres'])->toBe([['attribute_id = ?', '137'], ['entity_id = ?', '99']]);
});

it('resolves the entity type and the attribute once, however many products are read', function () {
    $queries = [];
    $reader  = attributeReaderFor(
        attributeReaderAnswers(['catalog_product_entity_varchar' => '1']),
        $queries
    );

    $reader->value('catalog_product_entity_varchar', '1', 'age_check');
    $reader->value('catalog_product_entity_varchar', '2', 'age_check');

    expect(array_column($queries, 'table'))->toBe([
        'eav_entity_type',
        'eav_attribute',
        'catalog_product_entity_varchar',
        'catalog_product_entity_varchar',
    ]);
});

it('answers null for an attribute that does not exist, without reading a value table', function () {
    $queries = [];

    expect(attributeReaderFor(['eav_entity_type' => '4'], $queries)
        ->value('catalog_product_entity_int', '1', 'nonesuch'))->toBeNull()
        ->and(array_column($queries, 'table'))->toBe(['eav_entity_type', 'eav_attribute']);
});

it('answers null for a product that has no row', function () {
    $queries = [];

    expect(attributeReaderFor(attributeReaderAnswers(), $queries)
        ->value('catalog_product_entity_int', '1', 'age_check'))->toBeNull();
});
