<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

/**
 * Object manager wired for CustomsItems' two batch lookups, shared by both customs paths.
 *
 * Product data (HS code, country of manufacture) is fetched in two batch queries keyed by product
 * id, never per item — that is what these mocks model.
 *
 * @param array<int,string>      $classifications product id => HS code
 * @param array<int,string|null> $countries       product id => country of manufacture
 */
function customsObjectManager(
    array  $classifications,
    array  $countries,
    Config $config,
    bool   $asGlobalInstance = false
): ObjectManagerInterface {
    $select = Mockery::mock();
    $select->shouldReceive('from')->andReturnSelf();
    $select->shouldReceive('where')->andReturnSelf();

    $connection = Mockery::mock(AdapterInterface::class);
    $connection->shouldReceive('select')->andReturn($select);
    $connection->shouldReceive('fetchOne')->andReturn('137'); // the classification attribute id
    $connection->shouldReceive('fetchPairs')->andReturn($classifications);

    $resource = Mockery::mock(ResourceConnection::class);
    $resource->shouldReceive('getConnection')->andReturn($connection);
    $resource->shouldReceive('getTableName')->andReturnUsing(fn (string $name) => $name);

    $products = [];

    foreach ($countries as $productId => $country) {
        $product = Mockery::mock();
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getCountryOfManufacture')->andReturn($country);
        $products[] = $product;
    }

    $productCollection = Mockery::mock(ProductCollection::class);
    $productCollection->shouldReceive('addIdFilter')->andReturnSelf();
    $productCollection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $productCollection->shouldReceive('getItems')->andReturn($products);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(ResourceConnection::class)->andReturn($resource);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($productCollection);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);
    $objectManager->shouldReceive('get')->with(Weight::class)->andReturn(new Weight($config));

    if ($asGlobalInstance) {
        ObjectManager::setInstance($objectManager);
    }

    return $objectManager;
}
