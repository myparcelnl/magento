<?php

declare(strict_types=1);

use Magento\Authorization\Model\UserContextInterface;
use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Service\ApiAccessToken\RandomBytesGeneratorInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

/**
 * Shared in-memory backing for the writer + collection factory so TokenService writes
 * and ApiAccessTokenUserContext / TokenScopeContext reads operate on the same row set —
 * which is what lets us assert a real rotation flow (write hash → present plaintext → match).
 */
final class MyParcelRotationHarness
{
    public const INTEGRATION_ID = 42;

    /** @var array<int, array{path: string, value: string, scope: string, scope_id: int}> */
    public array $rows = [];

    public function save(string $path, string $value, string $scope, int $scopeId): void
    {
        foreach ($this->rows as &$row) {
            if ($row['path'] === $path && $row['scope'] === $scope && $row['scope_id'] === $scopeId) {
                $row['value'] = $value;
                return;
            }
        }
        $this->rows[] = ['path' => $path, 'value' => $value, 'scope' => $scope, 'scope_id' => $scopeId];
    }

    public function valueAt(string $scope, int $scopeId): ?string
    {
        foreach ($this->rows as $row) {
            if ($row['scope'] === $scope && $row['scope_id'] === $scopeId) {
                return $row['value'];
            }
        }
        return null;
    }

    public function writer(): WriterInterface
    {
        $store  = $this;
        $writer = Mockery::mock(WriterInterface::class);
        $writer->shouldReceive('save')->andReturnUsing(
            function (string $path, string $value, string $scope, int $scopeId) use ($store): void {
                $store->save($path, $value, $scope, $scopeId);
            }
        );
        return $writer;
    }

    public function collectionFactory(): CollectionFactory
    {
        $store   = $this;
        $factory = Mockery::mock(CollectionFactory::class);
        $factory->shouldReceive('create')->andReturnUsing(function () use ($store) {
            $collection = Mockery::mock(ConfigDataCollection::class);
            $filters    = [];

            $collection->shouldReceive('addFieldToFilter')
                ->andReturnUsing(function ($field, $condition) use (&$filters, $collection) {
                    $filters[$field] = $condition;
                    return $collection;
                });

            $collection->shouldReceive('getItems')->andReturnUsing(function () use ($store, &$filters): array {
                $matched = [];
                foreach ($store->rows as $row) {
                    $ok = true;
                    foreach ($filters as $field => $cond) {
                        $value = $row[$field] ?? null;
                        if (is_array($cond) && array_key_exists('in', $cond)) {
                            if (! in_array($value, $cond['in'], true)) { $ok = false; break; }
                        } elseif ($value !== $cond) {
                            $ok = false; break;
                        }
                    }
                    if ($ok) {
                        $matched[] = new DataObject($row);
                    }
                }
                return $matched;
            });

            return $collection;
        });

        return $factory;
    }

    public function cacheTypeList(): TypeListInterface
    {
        $cache = Mockery::mock(TypeListInterface::class);
        $cache->shouldReceive('cleanType')->withAnyArgs()->andReturnNull();
        return $cache;
    }

    public function randomBytes(?string $forced = null): RandomBytesGeneratorInterface
    {
        $gen = Mockery::mock(RandomBytesGeneratorInterface::class);
        $gen->shouldReceive('generate')->andReturnUsing(
            static function (int $length = 32) use ($forced): string {
                return $forced ?? random_bytes($length);
            }
        );
        return $gen;
    }

    public function integrationService(): IntegrationServiceInterface
    {
        $integration = Mockery::mock(Integration::class);
        $integration->shouldReceive('getId')->andReturn(self::INTEGRATION_ID);

        $svc = Mockery::mock(IntegrationServiceInterface::class);
        $svc->shouldReceive('findByName')
            ->with(ApiAccessTokenUserContext::INTEGRATION_NAME)
            ->andReturn($integration);
        return $svc;
    }

    public function request(string $authHeader): RequestInterface
    {
        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('getHeader')->with('Authorization')->andReturn($authHeader);
        return $request;
    }

    /**
     * @param array<int, array{id: int, websiteId: int}> $stores
     */
    public function storeManager(array $stores): StoreManagerInterface
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

    public function service(?string $forcedBytes = null): TokenService
    {
        return new TokenService(
            $this->writer(),
            $this->collectionFactory(),
            $this->cacheTypeList(),
            $this->randomBytes($forcedBytes)
        );
    }

    /**
     * Builds a fresh UserContext + TokenScopeContext pair for the given auth header and
     * resolves them eagerly (calling getUserType() triggers the single processRequest pass).
     *
     * @param  array<int, array{id: int, websiteId: int}> $stores
     * @return array{0: ApiAccessTokenUserContext, 1: TokenScopeContext}
     */
    public function authenticate(string $authHeader, array $stores): array
    {
        $scope = new TokenScopeContext($this->collectionFactory(), $this->storeManager($stores));
        $ctx   = new ApiAccessTokenUserContext(
            $this->request($authHeader),
            $this->collectionFactory(),
            $this->integrationService(),
            $scope
        );
        $ctx->getUserType();
        return [$ctx, $scope];
    }
}

// ====================================================================
// Scenario 1 — rotation at the same coordinate overwrites the row atomically.
// ====================================================================

it('rotation at the same scope coordinate overwrites the hash row in place', function (string $scope, int $scopeId) {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();

    $t1 = $service->generateForScope($scope, $scopeId);
    expect($harness->rows)->toHaveCount(1);
    expect($harness->valueAt($scope, $scopeId))->toBe(hash('sha256', $t1));

    $t2 = $service->generateForScope($scope, $scopeId);

    expect($t2)->not->toBe($t1);
    expect($harness->rows)->toHaveCount(1);
    expect($harness->valueAt($scope, $scopeId))->toBe(hash('sha256', $t2));
    expect($harness->valueAt($scope, $scopeId))->not->toBe(hash('sha256', $t1));
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 2 — the previous token is rejected and the new one authenticates
// at the same scope coordinate. Covers the rotation effect on the REST entry point.
// ====================================================================

it('after rotation the previous token is rejected and the new one authenticates at the same scope', function (string $scope, int $scopeId) {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $t1 = $service->generateForScope($scope, $scopeId);
    $t2 = $service->generateForScope($scope, $scopeId);

    [$oldCtx] = $harness->authenticate('MyParcel ' . $t1, $stores);
    expect($oldCtx->getUserType())->toBeNull();
    expect($oldCtx->getUserId())->toBeNull();

    [$newCtx, $newScope] = $harness->authenticate('MyParcel ' . $t2, $stores);
    expect($newCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newCtx->getUserId())->toBe(MyParcelRotationHarness::INTEGRATION_ID);
    expect($newScope->getOwner())->toBe(['scope' => $scope, 'scopeId' => $scopeId]);
})->with([
    'default scope' => [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0],
    'website scope' => [ScopeInterface::SCOPE_WEBSITES, 1],
    'store scope'   => [ScopeInterface::SCOPE_STORES, 2],
]);

// ====================================================================
// Scenario 3 — rotation preserves the partition of permitted stores
// (ownership is row-coordinate, not hash-equality, so the new token sees the same stores).
// ====================================================================

it('rotation at default scope preserves the partition of permitted stores', function () {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();
    $stores  = [
        ['id' => 1, 'websiteId' => 1],
        ['id' => 2, 'websiteId' => 1],
        ['id' => 3, 'websiteId' => 2],
    ];

    $t1 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    [, $beforeScope] = $harness->authenticate('MyParcel ' . $t1, $stores);
    $before = $beforeScope->permittedStoreIds();

    $t2 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    [, $afterScope] = $harness->authenticate('MyParcel ' . $t2, $stores);

    expect($afterScope->permittedStoreIds())->toBe($before);
    expect($afterScope->permittedStoreIds())->toBe([1, 2, 3]);
});

// ====================================================================
// Scenario 4a — cross-scope isolation: rotating the default token does not
// touch a store-scoped token (or its partition carve-out).
// ====================================================================

it('rotating the default-scope token does not affect a store-scoped token', function () {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    $tDefault2 = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$oldDef] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($oldDef->getUserType())->toBeNull();

    [$newDef, $newDefScope] = $harness->authenticate('MyParcel ' . $tDefault2, $stores);
    expect($newDef->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newDefScope->permittedStoreIds())->toBe([1]);

    [$s2Ctx, $s2Scope] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($s2Ctx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($s2Scope->getOwner())->toBe(['scope' => ScopeInterface::SCOPE_STORES, 'scopeId' => 2]);
    expect($s2Scope->permittedStoreIds())->toBe([2]);
});

// ====================================================================
// Scenario 4b — cross-scope isolation: rotating a store-scoped token does not
// touch the default token (or its partition).
// ====================================================================

it('rotating a store-scoped token does not affect the default-scope token', function () {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();
    $stores  = [['id' => 1, 'websiteId' => 1], ['id' => 2, 'websiteId' => 1]];

    $tDefault = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $tStore2  = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);
    $defaultRowBefore = $harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    $tStore2Rotated = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->toBe($defaultRowBefore);

    [$oldS2] = $harness->authenticate('MyParcel ' . $tStore2, $stores);
    expect($oldS2->getUserType())->toBeNull();

    [$newS2, $newS2Scope] = $harness->authenticate('MyParcel ' . $tStore2Rotated, $stores);
    expect($newS2->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($newS2Scope->permittedStoreIds())->toBe([2]);

    [$defCtx, $defScope] = $harness->authenticate('MyParcel ' . $tDefault, $stores);
    expect($defCtx->getUserType())->toBe(UserContextInterface::USER_TYPE_INTEGRATION);
    expect($defScope->permittedStoreIds())->toBe([1]);
});

// ====================================================================
// Scenario 5 — native Bearer scheme is left to Magento's native chain
// regardless of rotation state on the MyParcel scheme.
// ====================================================================

it('the Bearer scheme is not intercepted by the MyParcel UserContext after a rotation', function () {
    $harness = new MyParcelRotationHarness();
    $service = $harness->service();

    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    [$ctx] = $harness->authenticate('Bearer some-admin-token', [['id' => 1, 'websiteId' => 1]]);

    expect($ctx->getUserType())->toBeNull();
    expect($ctx->getUserId())->toBeNull();
});

// ====================================================================
// Hash-collision on rotation (TR-000004 hash-uniqueness invariant, US-000002 tech note).
// Asserts the 409 defence applies just as much to a rotation as to a first generate,
// and that the existing row at the rotated coordinate is left intact.
// ====================================================================

it('a rotation that would collide with another scope\'s hash row is rejected and persists nothing', function () {
    $harness = new MyParcelRotationHarness();

    // Pre-seed a default-scope row whose hash matches the forced-bytes seed.
    $forcedBytes   = str_repeat("\x01", 32);
    $collidingHash = hash('sha256', bin2hex($forcedBytes));
    $harness->save(
        TokenService::CONFIG_PATH,
        $collidingHash,
        ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        0
    );

    // First, plant a real (random) token at stores:2.
    $original  = $harness->service()->generateForScope(ScopeInterface::SCOPE_STORES, 2);
    $originalHash = hash('sha256', $original);
    expect($harness->valueAt(ScopeInterface::SCOPE_STORES, 2))->toBe($originalHash);

    // Now rotate at stores:2 with bytes that would force the colliding hash.
    $forcedService = $harness->service($forcedBytes);
    expect(fn () => $forcedService->generateForScope(ScopeInterface::SCOPE_STORES, 2))
        ->toThrow(AlreadyExistsException::class);

    // The rotation didn't persist — stores:2 still holds the original token's hash.
    expect($harness->valueAt(ScopeInterface::SCOPE_STORES, 2))->toBe($originalHash);
    expect($harness->valueAt(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0))->toBe($collidingHash);
});
