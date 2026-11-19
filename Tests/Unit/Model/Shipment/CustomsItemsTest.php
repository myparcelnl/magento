<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Model\Shipment\CustomsItems;
use MyParcelNL\Magento\Service\Weight;

/**
 * The line arithmetic both export paths share. An amount a cent low on a customs declaration is an
 * under-declared value, so the rounding may not depend on the PHP version.
 */
function customsItems(): CustomsItems
{
    $config = createConfig(['print/weight_indication' => 'gram']);

    return new CustomsItems(Mockery::mock(ObjectManagerInterface::class), $config, new Weight($config));
}

it('answers the same on every PHP version when a line lands just short of a boundary', function () {
    // 25 cents times 4.1 is 102.49999999999999, below the boundary, so half up answers 102.
    // round() answers 103 before PHP 8.4, which pre-rounds to the boundary first.
    expect(customsItems()->lineValueInCents(0.25, 4.1))->toBe(102)
        ->and(customsItems()->lineValueInCents(0.25, 2.3))->toBe(57);
});

it('rounds a line that lands exactly on the boundary up', function () {
    expect(customsItems()->lineValueInCents(0.01, 2.5))->toBe(3)
        ->and(customsItems()->lineValueInCents(0.03, 1.5))->toBe(5);
});

it('leaves a line that converts exactly alone', function () {
    expect(customsItems()->lineValueInCents(12.34, 1.0))->toBe(1234)
        ->and(customsItems()->lineValueInCents(0.29, 3.0))->toBe(87);
});

it('declares a weightless line at the default weight, never at zero grams', function () {
    expect(customsItems()->lineWeightInGrams(0.0, 3.0))->toBe(Weight::DEFAULT_WEIGHT);
});

/**
 * A CustomsItems on a mocked product collection. Do not read the value tables directly: on Commerce
 * they are keyed by row_id.
 *
 * @param array<int,string|null> $classifications product id => stored HS code
 */
function customsItemsReading(array $classifications): CustomsItems
{
    $config   = createConfig(['print/weight_indication' => 'gram']);
    $filtered = [];

    $collection = Mockery::mock(ProductCollection::class);
    $collection->shouldReceive('addIdFilter')->andReturnUsing(
        static function (array $ids) use (&$filtered, $collection) {
            $filtered = $ids;

            return $collection;
        }
    );
    $collection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $collection->shouldReceive('getItems')->andReturnUsing(
        static function () use (&$filtered, $classifications): array {
            $products = [];

            foreach ($filtered as $id) {
                $product = Mockery::mock();
                $product->shouldReceive('getId')->andReturn($id);
                $product->shouldReceive('getData')->andReturnUsing(
                    static fn(string $code) => 'myparcel_classification' === $code
                        ? ($classifications[$id] ?? null)
                        : null
                );
                $products[] = $product;
            }

            return $products;
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    return new CustomsItems($objectManager, $config, new Weight($config));
}

it('answers HS codes keyed by the public product id', function () {
    $items = customsItemsReading([7 => '6109.10', 9 => '0901']);

    expect($items->classificationsFor([7, 9]))->toBe([7 => '6109.10', 9 => '0901']);
});

it('leaves a product with no HS code out, so the builder sends an empty one', function () {
    $items = customsItemsReading([7 => '6109.10']);

    expect($items->classificationsFor([7, 9]))->toBe([7 => '6109.10']);
});
