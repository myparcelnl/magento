<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/**
 * @param array<int, array{store_id: int, website_id: int}> $stores
 */
function makeStoreManager(array $stores): StoreManagerInterface
{
    $manager = Mockery::mock(StoreManagerInterface::class);

    $storeMocks = [];
    foreach ($stores as $row) {
        $store = Mockery::mock(StoreInterface::class);
        $store->shouldReceive('getId')->andReturn($row['store_id']);
        $store->shouldReceive('getWebsiteId')->andReturn($row['website_id']);
        $storeMocks[] = $store;
    }

    $manager->shouldReceive('getStores')->with(false)->andReturn($storeMocks);
    return $manager;
}

/**
 * @param array<int, array{scope: string, scope_id: int}> $rows
 */
function makeConfigDataCollectionFactory(array $rows): CollectionFactory
{
    return makeConfigDataCollectionFactoryWithCallCount($rows, $callCount);
}

/**
 * @param array<int, array{scope: string, scope_id: int}> $rows
 * @param-out int $callCount
 */
function makeConfigDataCollectionFactoryWithCallCount(array $rows, ?int &$callCount): CollectionFactory
{
    $callCount = 0;

    $items = array_map(static function (array $row): DataObject {
        return new DataObject($row);
    }, $rows);

    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andReturnUsing(function () use (&$callCount, $items): ConfigDataCollection {
        $callCount++;
        $collection = Mockery::mock(ConfigDataCollection::class);
        $collection->shouldReceive('addFieldToFilter')->andReturnSelf();
        $collection->shouldReceive('getItems')->andReturn($items);
        return $collection;
    });

    return $factory;
}

/** Standard 2-website fixture: W1={s1,s2}, W2={s3,s4} */
function fourStoreFixture(): array
{
    return [
        ['store_id' => 1, 'website_id' => 1],
        ['store_id' => 2, 'website_id' => 1],
        ['store_id' => 3, 'website_id' => 2],
        ['store_id' => 4, 'website_id' => 2],
    ];
}

it('permittedStoreIds returns null when no owner has been set', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([]),
        makeStoreManager(fourStoreFixture())
    );

    expect($context->permittedStoreIds())->toBeNull();
});

it('default-scope owner with no other rows owns every non-admin store', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([1, 2, 3, 4]);
});

it('default-scope owner has store 2 carved out by a (stores, 2) row', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([1, 3, 4]);
});

it('default-scope owner loses entire website W1 when a (websites, 1) row exists, even with no store-tier row', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([3, 4]);
});

it('website-scope owner sees only its own stores minus store-tier carve-outs', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_WEBSITES, 1);

    expect($context->permittedStoreIds())->toBe([1]);
});

it('store-scope owner sees only that store', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    expect($context->permittedStoreIds())->toBe([2]);
});

it('admin store (id 0) is never returned because StoreManager::getStores(false) excludes it', function () {
    // Fixture: a 'stores, 0' row would be illegal in production, but we assert that
    // even if such a row existed, the admin store is not iterated by getStores(false).
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 0],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 0);

    expect($context->permittedStoreIds())->toBe([]);
});

it('memoizes the configuration row read across repeated permittedStoreIds() calls', function () {
    $factory = makeConfigDataCollectionFactoryWithCallCount(
        [['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0]],
        $callCount
    );

    $context = new TokenScopeContext($factory, makeStoreManager(fourStoreFixture()));
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    $context->permittedStoreIds();
    $context->permittedStoreIds();
    $context->permittedStoreIds();

    expect($callCount)->toBe(1);
});

it('_resetState() clears owner and memoized rows so subsequent calls return null', function () {
    $factory = makeConfigDataCollectionFactoryWithCallCount(
        [['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0]],
        $callCount
    );

    $context = new TokenScopeContext($factory, makeStoreManager(fourStoreFixture()));
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $context->permittedStoreIds();

    $context->_resetState();

    expect($context->getOwner())->toBeNull();
    expect($context->permittedStoreIds())->toBeNull();
});

it('assertStoreInScope is a no-op when no token authenticated this request', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([]),
        makeStoreManager(fourStoreFixture())
    );

    expect(fn () => $context->assertStoreInScope(99))->not->toThrow(NoSuchEntityException::class);
});

it('assertStoreInScope throws NoSuchEntityException for a store outside the permitted set', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    $context->assertStoreInScope(3);
})->throws(NoSuchEntityException::class);

it('assertStoreInScope passes for a store inside the permitted set', function () {
    $context = new TokenScopeContext(
        makeConfigDataCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        makeStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    expect(fn () => $context->assertStoreInScope(2))->not->toThrow(NoSuchEntityException::class);
});
