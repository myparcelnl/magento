<?php

declare(strict_types=1);

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use MyParcelNL\Magento\Setup\Migrations\SortProductAttributes;

/**
 * @param array<string,int> $attributeIds attribute code => id; a code left out does not exist
 *
 * @return array<int,array{table: string, bind: array, where: array}> the updates sent
 */
function runSortProductAttributes(array $attributeIds): array
{
    $updates = [];

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('update')->andReturnUsing(
        static function (string $table, array $bind, array $where) use (&$updates): int {
            $updates[] = ['table' => $table, 'bind' => $bind, 'where' => $where];

            return 1;
        }
    );

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(static fn(string $name): string => $name);

    $eavSetup = Mockery::mock(EavSetup::class);
    $eavSetup->shouldReceive('getAttributeId')->andReturnUsing(
        static fn(string $entity, string $code) => Product::ENTITY === $entity ? ($attributeIds[$code] ?? false) : false
    );

    (new SortProductAttributes($resource))->run($eavSetup);

    return $updates;
}

it('gives each attribute its place in the PDK order, the external product reference right after the HS code', function () {
    $ids    = ['myparcel_fit_in_mailbox' => 101, 'myparcel_classification' => 102, 'myparcel_external_product_reference' => 103, 'myparcel_age_check' => 104];
    $codeOf = array_flip($ids);
    $order  = [];

    foreach (runSortProductAttributes($ids) as $update) {
        $order[$update['bind']['sort_order']] = $codeOf[$update['where']['attribute_id = ?']];
    }

    expect(array_keys($order))->toBe([1, 6, 7, 8])
        ->and($order[7])->toBe('myparcel_external_product_reference')
        ->and($order[6])->toBe('myparcel_classification');
});

it('skips an attribute that does not exist', function () {
    $updates = runSortProductAttributes(['myparcel_classification' => 138]);

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['where']['attribute_id = ?'])->toBe(138);
});

it('moves an attribute only inside the MyParcel group, not where a merchant put it', function () {
    $updates = runSortProductAttributes(['myparcel_classification' => 138]);

    expect($updates[0]['table'])->toBe('eav_entity_attribute')
        ->and(array_values($updates[0]['where']))->toContain('MyParcel Options');
});
