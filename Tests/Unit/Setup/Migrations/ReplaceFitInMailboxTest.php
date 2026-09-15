<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelNL\Magento\Setup\Migrations\ReplaceFitInMailbox;
use MyParcelNL\Magento\Setup\QueryBuilder;

/**
 * The migration reads the old percentage values through a join on the catalog value table, so the
 * join has to name the column that table is actually keyed by.
 *
 * @return object{queries: string[]} every statement the migration handed the connection
 */
function runReplaceFitInMailbox(string $linkField = 'entity_id', array $rows = []): object
{
    $did = new class { public array $queries = []; };

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('fetchAll')->andReturnUsing(
        static function ($query) use ($did, $rows): array {
            $did->queries[] = (string) $query;

            return $rows;
        }
    );
    $connection->shouldReceive('query')->andReturnUsing(
        static function ($query) use ($did) {
            $did->queries[] = (string) $query;

            return null;
        }
    );

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with('Magento\Framework\App\ResourceConnection')->andReturn($resource);
    $objectManager->shouldReceive('get')->with(MetadataPool::class)->andReturn(productMetadataPool($linkField));
    ObjectManager::setInstance($objectManager);

    $setup = Mockery::mock(SchemaSetupInterface::class);
    $setup->shouldReceive('getTable')->andReturnUsing(static fn(string $name): string => $name);

    (new ReplaceFitInMailbox(new QueryBuilder(), $setup))->updateCatalogProductEntity();

    return $did;
}

it('joins on the link field the install actually uses', function ($linkField) {
    // Commerce keys the catalog value tables on row_id. A hardcoded entity_id is an unknown column
    // there, so setup:upgrade dies before a single value is converted.
    $did = runReplaceFitInMailbox($linkField);

    expect($did->queries[0])->toContain(
        sprintf('product.%s = catalog_product_entity_varchar.%s', $linkField, $linkField)
    );
})->with([
    'open source' => ['entity_id'],
    'commerce'    => ['row_id'],
]);

it('never selects a column the staged schema does not have', function () {
    // value_id, the value table's own primary key, is what the UPDATE filters on. The entity id was
    // selected and never read.
    $did = runReplaceFitInMailbox('row_id');

    expect($did->queries[0])->not->toContain('catalog_product_entity_varchar.entity_id');
});

it('updates each row it found by value_id', function () {
    $did = runReplaceFitInMailbox('row_id', [
        ['value_id' => 7, 'value' => '50', 'attribute_id' => 137],
    ]);

    expect($did->queries)->toHaveCount(2)
        ->and($did->queries[1])->toContain('value_id = \'7\'');
});
