<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Authorization\ApiAccessTokenUserContext;
use MyParcelNL\Magento\Model\Authorization\TokenScopeContext;
use MyParcelNL\Magento\Service\ApiAccessToken\RandomBytesGeneratorInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

/**
 * Shared in-memory backing for the writer + collection factory so TokenService writes/deletes
 * and ApiAccessTokenUserContext / TokenScopeContext reads operate on the same row set — the
 * shape used by every token-lifecycle test (rotation, revocation, future operations).
 */
final class MyParcelTokenLifecycleHarness
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

    public function delete(string $path, string $scope, int $scopeId): void
    {
        foreach ($this->rows as $index => $row) {
            if ($row['path'] === $path && $row['scope'] === $scope && $row['scope_id'] === $scopeId) {
                array_splice($this->rows, $index, 1);
                return;
            }
        }
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
        $writer->shouldReceive('delete')->andReturnUsing(
            function (string $path, string $scope = 'default', int $scopeId = 0) use ($store): void {
                $store->delete($path, $scope, $scopeId);
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
