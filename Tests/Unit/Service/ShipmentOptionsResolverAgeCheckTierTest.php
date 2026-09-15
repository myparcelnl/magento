<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\ProductAttributes;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;

/**
 * The product tier of the age check, which DefaultOptions::hasOptionSet() reads between the
 * checkout's choice and the carrier setting.
 *
 * Null is the tier's "no opinion" and the caller depends on it: a false where nothing was set
 * silences the carrier default below it. These cases pin all three answers against a quote whose
 * products disagree, which the same-value-everywhere cases cannot reach.
 *
 * @param array<int,string> $values product id => stored myparcel_age_check value
 */
function ageCheckProductTierQuote(array $values, ?array &$loads = null): array
{
    $loads    = [];
    $filtered = [];

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
        static function () use (&$filtered, $values): array {
            $products = [];

            foreach ($filtered as $id) {
                $product = Mockery::mock();
                $product->shouldReceive('getId')->andReturn($id);
                $product->shouldReceive('getData')->andReturnUsing(
                    static fn(string $code) => 'myparcel_age_check' === $code ? ($values[$id] ?? null) : null
                );
                $products[] = $product;
            }

            return $products;
        }
    );

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);

    // One reader for the request, as di gives the resolver: a reader per call memoised nothing.
    $objectManager->shouldReceive('get')
                  ->with(ProductAttributes::class)
                  ->andReturn(new ProductAttributes($objectManager));

    ObjectManager::setInstance($objectManager);

    $items = [];

    foreach (array_keys($values) as $productId) {
        $items[] = ['product_id' => $productId];
    }

    return $items;
}

it('is on when any product says so, even behind one that says no', function () {
    $items = ageCheckProductTierQuote([7 => '0', 9 => '1']);

    expect(ShipmentOptionsResolver::getAgeCheckFromProduct($items))->toBeTrue();
});

it('is off when every product that speaks says no', function () {
    $items = ageCheckProductTierQuote([7 => '0', 9 => '0']);

    expect(ShipmentOptionsResolver::getAgeCheckFromProduct($items))->toBeFalse();
});

it('has no opinion when no product carries the attribute', function () {
    // Not false: false is an opinion, and it would stop the carrier default from ever being read.
    $items = ageCheckProductTierQuote([7 => '', 9 => '']);

    expect(ShipmentOptionsResolver::getAgeCheckFromProduct($items))->toBeNull();
});

it('has no opinion about an empty quote', function () {
    expect(ShipmentOptionsResolver::getAgeCheckFromProduct(ageCheckProductTierQuote([])))->toBeNull();
});

it('reads the whole quote in one load', function () {
    // It built a reader per product before, so nothing it memoised survived an iteration and an
    // N-line quote paid 3N queries on the checkout path.
    $items = ageCheckProductTierQuote([7 => '0', 9 => '0', 11 => '0'], $loads);

    ShipmentOptionsResolver::getAgeCheckFromProduct($items);

    expect($loads)->toHaveCount(1)
        ->and($loads[0])->toBe([7, 9, 11]);
});
