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

it('sorts the attributes in the order of the PDK product settings', function () {
    $codes   = [
        'myparcel_age_check',
        'myparcel_classification',
        'myparcel_digital_stamp',
        'myparcel_disable_checkout',
        'myparcel_dropoff_delay',
        'myparcel_exclude_parcel_lockers',
        'myparcel_external_product_reference',
        'myparcel_fit_in_mailbox',
        'myparcel_priority_delivery',
    ];
    $ids     = array_combine($codes, range(101, 109));
    $codeOf  = array_flip($ids);
    $order   = [];

    foreach (runSortProductAttributes($ids) as $update) {
        $order[$codeOf[$update['where']['attribute_id = ?']]] = $update['bind']['sort_order'];
    }

    asort($order);

    expect(array_keys($order))->toBe([
        'myparcel_fit_in_mailbox',
        'myparcel_digital_stamp',
        'myparcel_dropoff_delay',
        'myparcel_disable_checkout',
        'myparcel_exclude_parcel_lockers',
        'myparcel_classification',
        'myparcel_external_product_reference',
        'myparcel_age_check',
        'myparcel_priority_delivery',
    ]);
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
