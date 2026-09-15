<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\ProductAttributes;

/**
 * The module's product attributes are read through the product collection, never the EAV value
 * tables: Commerce keys those on row_id and holds one row per scheduled update, so a hand-built
 * query answers the wrong version or nothing at all.
 *
 * These cases pin the two things that matter to the callers: the map is keyed by public product id,
 * and a batch costs one load.
 *
 * @param array<int,array<string,string|null>> $rows product id => attribute code => value
 */
function productAttributesFor(array $rows, ?array &$loads = null): ProductAttributes
{
    $loads = [];

    $collection = Mockery::mock(ProductCollection::class);
    $filtered   = [];

    $collection->shouldReceive('addIdFilter')->andReturnUsing(
        static function (array $ids) use (&$filtered, &$loads, $collection) {
            $filtered = $ids;
            $loads[]  = $ids;

            return $collection;
        }
    );
    $collection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $collection->shouldReceive('getItems')->andReturnUsing(
        static function () use (&$filtered, $rows): array {
            $products = [];

            foreach ($filtered as $id) {
                if (! array_key_exists($id, $rows)) {
                    continue;
                }

                $product = Mockery::mock();
                $product->shouldReceive('getId')->andReturn($id);
                $product->shouldReceive('getData')->andReturnUsing(
                    static fn(string $code) => $rows[$id][$code] ?? null
                );
                $products[] = $product;
            }

            return $products;
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    return new ProductAttributes($objectManager);
}

it('answers a column keyed by the public product id', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_classification' => '6109.10'],
        9 => ['myparcel_classification' => '0901'],
    ]);

    expect($attributes->column([7, 9], 'classification'))->toBe([7 => '6109.10', 9 => '0901']);
});

it('reads a whole batch in one load', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_classification' => '61'],
        9 => ['myparcel_classification' => '62'],
    ], $loads);

    $attributes->column([7, 9], 'classification');

    expect($loads)->toHaveCount(1)
        ->and($loads[0])->toBe([7, 9]);
});

it('never asks twice for a product it already loaded', function () {
    $attributes = productAttributesFor([7 => ['myparcel_age_check' => '1']], $loads);

    $attributes->warm([7]);
    $attributes->value(7, 'age_check');
    $attributes->column([7], 'age_check');

    expect($loads)->toHaveCount(1);
});

it('asks nothing at all for an empty batch', function () {
    $attributes = productAttributesFor([], $loads);

    expect($attributes->column([], 'classification'))->toBe([])
        ->and($loads)->toBeEmpty();
});

it('leaves a product with no value out of the map, which is the callers no opinion', function () {
    $attributes = productAttributesFor([
        7 => ['myparcel_age_check' => '1'],
        9 => [],
    ]);

    expect($attributes->column([7, 9], 'age_check'))->toBe([7 => '1']);
});

it('treats an empty string as a row that says nothing', function () {
    // The raw reader answered '' here, and every caller then had to test for it separately.
    $attributes = productAttributesFor([7 => ['myparcel_age_check' => '']]);

    expect($attributes->column([7], 'age_check'))->toBe([])
        ->and($attributes->value(7, 'age_check'))->toBeNull();
});

it('does not re-query a product the collection never returned', function () {
    // Deleted, or out of this store. Absent is an answer, not a miss to retry.
    $attributes = productAttributesFor([], $loads);

    $attributes->value(7, 'age_check');
    $attributes->value(7, 'age_check');

    expect($loads)->toHaveCount(1)
        ->and($attributes->value(7, 'age_check'))->toBeNull();
});
