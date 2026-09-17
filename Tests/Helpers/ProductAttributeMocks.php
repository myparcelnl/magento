<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\ProductAttributes;

/**
 * A product collection answering the given rows, recording each id filter it was given.
 *
 * Kept separate from productAttributesFor() because PackageRepository builds its own
 * ProductAttributes off the static ObjectManager, so a caller stubbing that singleton needs the
 * collection rather than the service.
 *
 * @param array<int,array<string,string|null>> $rows product id => attribute code => value
 * @param null|array                           $loads receives one entry per load, each the ids asked for
 */
function productCollectionFor(array $rows, ?array &$loads = null): ProductCollection
{
    $loads      = [];
    $filtered   = [];
    $collection = Mockery::mock(ProductCollection::class);

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

    return $collection;
}

/**
 * The module's product attributes are read through the product collection, never the EAV value
 * tables: Commerce keys those on row_id and holds one row per scheduled update, so a hand-built
 * query answers the wrong version or nothing at all.
 *
 * @param array<int,array<string,string|null>> $rows product id => attribute code => value
 */
function productAttributesFor(array $rows, ?array &$loads = null): ProductAttributes
{
    $collection = productCollectionFor($rows, $loads);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    return new ProductAttributes($objectManager);
}
