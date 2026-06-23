<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;

/** Standard 2-website fixture: W1={store 1, store 2}, W2={store 3, store 4} */
function fourStoreFixture(): array
{
    return [
        ['id' => 1, 'websiteId' => 1],
        ['id' => 2, 'websiteId' => 1],
        ['id' => 3, 'websiteId' => 2],
        ['id' => 4, 'websiteId' => 2],
    ];
}

it('permittedStoreIds returns null when no owner has been set', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([]),
        mockStoreManager(fourStoreFixture())
    );

    expect($context->permittedStoreIds())->toBeNull();
});

it('default-scope owner with no other rows owns every non-admin store', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([1, 2, 3, 4]);
});

it('default-scope owner has store 2 carved out by a (stores, 2) row', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([1, 3, 4]);
});

it('default-scope owner loses entire website 1 when a (websites, 1) row exists, even with no store-tier row', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($context->permittedStoreIds())->toBe([3, 4]);
});

it('website-scope owner sees only its own stores minus store-tier carve-outs', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_WEBSITES, 1);

    expect($context->permittedStoreIds())->toBe([1]);
});

it('store-scope owner sees only that store', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    expect($context->permittedStoreIds())->toBe([2]);
});

it('admin store (id 0) is never returned because StoreManager::getStores(false) excludes it', function () {
    // Fixture: a 'stores, 0' row would be illegal in production, but we assert that
    // even if such a row existed, the admin store is not iterated by getStores(false).
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 0],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 0);

    expect($context->permittedStoreIds())->toBe([]);
});

it('memoizes the configuration row read across repeated permittedStoreIds() calls', function () {
    $factory = mockCollectionFactory(
        [['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0]],
        $callCount
    );

    $context = new TokenScopeContext($factory, mockStoreManager(fourStoreFixture()));
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    $context->permittedStoreIds();
    $context->permittedStoreIds();
    $context->permittedStoreIds();

    expect($callCount)->toBe(1);
});

it('_resetState() clears owner and memoized rows so subsequent calls return null', function () {
    $factory = mockCollectionFactory(
        [['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0]],
        $callCount
    );

    $context = new TokenScopeContext($factory, mockStoreManager(fourStoreFixture()));
    $context->setOwner(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $context->permittedStoreIds();

    $context->_resetState();

    expect($context->getOwner())->toBeNull();
    expect($context->permittedStoreIds())->toBeNull();
});

it('assertStoreInScope is a no-op when no token authenticated this request', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([]),
        mockStoreManager(fourStoreFixture())
    );

    expect(fn () => $context->assertStoreInScope(99))->not->toThrow(NoSuchEntityException::class);
});

it('assertStoreInScope throws NoSuchEntityException for a store outside the permitted set', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    $context->assertStoreInScope(3);
})->throws(NoSuchEntityException::class);

it('assertStoreInScope passes for a store inside the permitted set', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 2],
        ]),
        mockStoreManager(fourStoreFixture())
    );
    $context->setOwner(ScopeInterface::SCOPE_STORES, 2);

    expect(fn () => $context->assertStoreInScope(2))->not->toThrow(NoSuchEntityException::class);
});

// US-000006 Scenario 9: a website containing zero store-views still authenticates,
// but its permitted set is empty (no store has website 3 as its parent).
it('website-scope owner whose website has zero member stores returns an empty permitted set', function () {
    $fixtureWithW3 = array_merge(fourStoreFixture(), []); // website 3 intentionally has no stores
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 3],
        ]),
        mockStoreManager($fixtureWithW3)
    );
    $context->setOwner(ScopeInterface::SCOPE_WEBSITES, 3);

    expect($context->permittedStoreIds())->toBe([]);
});

// ====================================================================
// findByHash: timing-safe match against the stored SHA-256 hash row set.
// ====================================================================

it('findByHash returns the (scope, scopeId) of the row whose stored hash matches the presented hash', function () {
    $plaintext = 'plaintext-token-find';
    $context   = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'something-else')],
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1, 'value' => hash('sha256', $plaintext)],
        ]),
        mockStoreManager([])
    );

    expect($context->findByHash(hash('sha256', $plaintext)))
        ->toBe(['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 1]);
});

it('findByHash returns null when no stored row matches the presented hash', function () {
    $context = new TokenScopeContext(
        mockCollectionFactory([
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', 'unrelated')],
        ]),
        mockStoreManager([])
    );

    expect($context->findByHash(hash('sha256', 'not-the-plaintext')))->toBeNull();
});

it('findByHash and permittedStoreIds share a single row load across the request', function () {
    $plaintext = 'plaintext-shared-load';
    $factory   = mockCollectionFactory(
        [['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => hash('sha256', $plaintext)]],
        $createCalls
    );

    $context = new TokenScopeContext($factory, mockStoreManager(fourStoreFixture()));

    $matched = $context->findByHash(hash('sha256', $plaintext));
    expect($matched)->toBe(['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0]);

    $context->setOwner($matched['scope'], $matched['scopeId']);
    expect($context->permittedStoreIds())->toBe([1, 2, 3, 4]);

    // Single DB round-trip across both findByHash + permittedStoreIds.
    expect($createCalls)->toBe(1);
});
