<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

/**
 * A PackageRepository with its protected config and product readers open to stubbing.
 *
 * Only the construction is shared. Each caller stubs getConfigValue() its own way, because the
 * cases differ on whether the store id, the whole path, or neither is the key.
 *
 * The singleton is installed here because every product-list method warms a product collection
 * before it reads anything, so even a case that stubs the read still needs a container.
 *
 * @return PackageRepository|Mockery\MockInterface
 */
function makePackageRepository(): PackageRepository
{
    mockAttributeValueLookup('');

    return Mockery::mock(PackageRepository::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();
}

/**
 * A quote item carrying one catalogue product id — the minimum PackageRepository reads off an item
 * before it warms the product attributes.
 *
 * Pass null for an item whose product was deleted, which the repository has to survive.
 */
function quoteItemFor(?int $productId = 1, float $qty = 1.0, float $weight = 0.0): object
{
    $catalogProduct = null;

    if (null !== $productId) {
        $catalogProduct = Mockery::mock();
        $catalogProduct->shouldReceive('getId')->andReturn($productId);
    }

    $item = Mockery::mock();
    $item->shouldReceive('getProduct')->andReturn($catalogProduct);
    $item->shouldReceive('getQty')->andReturn($qty);
    $item->shouldReceive('getWeight')->andReturn($weight);

    return $item;
}
