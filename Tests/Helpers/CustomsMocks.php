<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

/**
 * Object manager wired for CustomsItems' two batch lookups, shared by both customs paths.
 *
 * Product data (the myparcel_ attributes, country of manufacture) is fetched in batch queries keyed
 * by product id, never per item — that is what these mocks model. Only ids in $countries exist.
 *
 * @param array<string,array<int,string>> $attributes  column without myparcel_ => product id => value
 * @param array<int,string|null>          $countries   product id => country of manufacture
 * @param string                          $homeCountry the account's, which an empty country of origin setting falls back to
 */
function customsObjectManager(
    array  $attributes,
    array  $countries,
    Config $config,
    bool   $asGlobalInstance = false,
    string $homeCountry = 'NL'
): ObjectManagerInterface {
    $products = [];

    foreach ($countries as $productId => $country) {
        $product = Mockery::mock();
        $product->shouldReceive('getId')->andReturn($productId);
        $product->shouldReceive('getCountryOfManufacture')->andReturn($country);
        $product->shouldReceive('getData')->andReturnUsing(
            static function (string $code) use ($productId, $attributes) {
                return $attributes[substr($code, strlen('myparcel_'))][$productId] ?? null;
            }
        );
        $products[] = $product;
    }

    // Both lookups now read the same collection: the HS codes through ProductAttributes and the
    // countries directly. One mock answers both.
    $productCollection = Mockery::mock(ProductCollection::class);
    $productCollection->shouldReceive('addIdFilter')->andReturnSelf();
    $productCollection->shouldReceive('addAttributeToSelect')->andReturnSelf();
    $productCollection->shouldReceive('getItems')->andReturn($products);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($productCollection);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn($config);
    $objectManager->shouldReceive('get')->with(Weight::class)->andReturn(new Weight($config));
    $objectManager->shouldReceive('get')->with(StoredAccount::class)->andReturn(storedAccountAt($homeCountry));

    if ($asGlobalInstance) {
        ObjectManager::setInstance($objectManager);
    }

    return $objectManager;
}
