<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Service\ApiAccessToken\RandomBytesGeneratorInterface;

/**
 * Stateless mock builders shared across unit tests. Stateful lifecycle scenarios
 * (rotation, revocation chains) live in MyParcelTokenLifecycleHarness; this file
 * is for one-shot fakes that return whatever rows the caller hands in, with
 * addFieldToFilter() acting as a no-op pass-through.
 */

/**
 * @param array<int, array<string, mixed>> $rows
 * @param int|null                         $createCalls Out: incremented on every $factory->create().
 */
function mockCollectionFactory(array $rows = [], ?int &$createCalls = null): CollectionFactory
{
    $createCalls ??= 0;

    $items = array_map(static fn (array $row) => new DataObject($row), $rows);

    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andReturnUsing(function () use (&$createCalls, $items): ConfigDataCollection {
        $createCalls++;
        $collection = Mockery::mock(ConfigDataCollection::class);
        $collection->shouldReceive('addFieldToFilter')->andReturnSelf();
        $collection->shouldReceive('getItems')->andReturn($items);
        return $collection;
    });
    return $factory;
}

function mockCacheTypeList(): TypeListInterface
{
    $cache = Mockery::mock(TypeListInterface::class);
    $cache->shouldReceive('cleanType')->withAnyArgs()->andReturnNull();
    return $cache;
}

function mockRandomBytesGenerator(?string $forced = null): RandomBytesGeneratorInterface
{
    $gen = Mockery::mock(RandomBytesGeneratorInterface::class);
    $gen->shouldReceive('generate')->andReturnUsing(
        static fn (int $length = 32): string => $forced ?? random_bytes($length)
    );
    return $gen;
}

function mockRequestWithAuthorization(?string $value): RequestInterface
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getHeader')->with('Authorization')->andReturn($value);
    return $request;
}

function mockIntegrationService(?int $id): IntegrationServiceInterface
{
    $integration = Mockery::mock(Integration::class);
    $integration->shouldReceive('getId')->andReturn($id);

    $service = Mockery::mock(IntegrationServiceInterface::class);
    $service->shouldReceive('findByName')
        ->with(ApiAccessTokenUserContext::INTEGRATION_NAME)
        ->andReturn($integration);
    return $service;
}

/**
 * @param array<int, array{id: int, websiteId: int}> $stores
 */
function mockStoreManager(array $stores): StoreManagerInterface
{
    $storeMocks = [];
    foreach ($stores as $s) {
        $store = Mockery::mock(StoreInterface::class);
        $store->shouldReceive('getId')->andReturn($s['id']);
        $store->shouldReceive('getWebsiteId')->andReturn($s['websiteId']);
        $storeMocks[] = $store;
    }
    $mgr = Mockery::mock(StoreManagerInterface::class);
    $mgr->shouldReceive('getStores')->with(false)->andReturn($storeMocks);
    return $mgr;
}
