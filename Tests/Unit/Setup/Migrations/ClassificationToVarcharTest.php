<?php

declare(strict_types=1);

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MyParcelNL\Magento\Setup\Migrations\ClassificationToVarchar;

/**
 * Moves myparcel_classification out of the INT value table, where a leading zero and a dot could
 * not survive. The SQL is raw, so what it sends is the thing worth asserting: the migration runs
 * offline and a wrong statement is a column of HS codes silently emptied.
 *
 * @param int|null $attributeId what EavSetup answers for the attribute; 0 means it does not exist
 *
 * @return array{0: object, 1: callable} a recorder of what was sent, and the run
 */
function runClassificationToVarchar(?int $attributeId = 137, string $linkField = 'entity_id'): object
{
    $did = new class {
        /** @var array<int,array{sql: string, bind: array}> */
        public array $queries = [];
        /** @var array<int,array{table: string, where: array}> */
        public array $deletes = [];
        /** @var array<int,array{code: string, field: string, value: mixed}> */
        public array $attributeUpdates = [];
    };

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('query')->andReturnUsing(
        static function (string $sql, array $bind = []) use ($did) {
            $did->queries[] = ['sql' => $sql, 'bind' => $bind];

            return null;
        }
    );
    $connection->shouldReceive('delete')->andReturnUsing(
        static function (string $table, array $where) use ($did): int {
            $did->deletes[] = ['table' => $table, 'where' => $where];

            return 0;
        }
    );

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $name): string => $name);

    $metadataPool = productMetadataPool($linkField);

    $eavSetup = Mockery::mock(EavSetup::class);
    $eavSetup->shouldReceive('getAttributeId')
             ->with(Product::ENTITY, 'myparcel_classification')
             ->andReturn($attributeId);
    $eavSetup->shouldReceive('updateAttribute')->andReturnUsing(
        static function ($entity, $code, $field, $value) use ($did) {
            $did->attributeUpdates[] = ['code' => $code, 'field' => $field, 'value' => $value];

            return null;
        }
    );

    (new ClassificationToVarchar($resource, $metadataPool))->run($eavSetup);

    return $did;
}

it('does nothing at all when the attribute was never installed', function () {
    // A fresh install that never had the int-typed attribute must not be told to migrate it.
    $did = runClassificationToVarchar(0);

    expect($did->attributeUpdates)->toBe([])
        ->and($did->queries)->toBe([])
        ->and($did->deletes)->toBe([]);
});

it('retypes the attribute to varchar and widens its validation', function () {
    $did = runClassificationToVarchar();

    $byField = array_column($did->attributeUpdates, 'value', 'field');

    expect($byField['backend_type'])->toBe('varchar')
        ->and($byField['frontend_class'])->toContain('maximum-length-18')
        ->and($byField['default_value'])->toBe('');
});

it('copies the values across bound to the attribute id, never interpolated', function () {
    $did = runClassificationToVarchar();

    expect($did->queries)->toHaveCount(1)
        ->and($did->queries[0]['bind'])->toBe(['attribute_id' => 137])
        ->and($did->queries[0]['sql'])->toContain(':attribute_id')
        ->and($did->queries[0]['sql'])->not->toContain('137');
});

it('leaves a zero behind rather than exporting a literal "0" as an HS code', function () {
    // 0 was the old column default, not a code anyone typed.
    $did = runClassificationToVarchar();

    expect($did->queries[0]['sql'])->toContain('value <> 0');
});

it('casts to CHAR, which is what keeps a leading zero and a dot', function () {
    $did = runClassificationToVarchar();

    expect($did->queries[0]['sql'])->toContain('CAST(value AS CHAR)');
});

it('empties the int table for this attribute only', function () {
    $did = runClassificationToVarchar();

    expect($did->deletes)->toHaveCount(1)
        ->and($did->deletes[0]['table'])->toBe('catalog_product_entity_int')
        ->and($did->deletes[0]['where'])->toBe(['attribute_id = ?' => 137]);
});

it('keys the copy on the link field the install actually uses', function ($linkField) {
    // Commerce with content staging keys the EAV value tables on row_id; a hardcoded entity_id
    // fails there before a single code moves.
    $did = runClassificationToVarchar(137, $linkField);

    expect(substr_count($did->queries[0]['sql'], $linkField))->toBeGreaterThanOrEqual(2);
})->with([
    'open source' => ['entity_id'],
    'commerce'    => ['row_id'],
]);
