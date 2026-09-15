<?php

declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * A MetadataPool answering one link field for catalog_product.
 *
 * Open Source keys the catalog value tables on `entity_id`, Commerce on `row_id`. Every test that
 * covers a staged schema needs the same three-mock stack, so it lives here once.
 */
function productMetadataPool(string $linkField = 'entity_id'): MetadataPool
{
    $metadata = Mockery::mock(EntityMetadataInterface::class);
    $metadata->shouldReceive('getLinkField')->andReturn($linkField);

    $pool = Mockery::mock(MetadataPool::class);
    $pool->shouldReceive('getMetadata')->with(ProductInterface::class)->andReturn($metadata);

    return $pool;
}
