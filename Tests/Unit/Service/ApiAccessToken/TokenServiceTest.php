<?php

declare(strict_types=1);

use Magento\Config\Model\ResourceModel\Config\Data\Collection as ConfigDataCollection;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\InputException;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\RandomBytesGeneratorInterface;
use MyParcelNL\Magento\Service\ApiAccessToken\TokenService;

function createCollectionFactory(array $existingRows = []): CollectionFactory
{
    $collection = Mockery::mock(ConfigDataCollection::class);

    $collection->shouldReceive('addFieldToFilter')->andReturnSelf();

    $rows = array_map(static function (array $row): DataObject {
        return new DataObject($row);
    }, $existingRows);

    $collection->shouldReceive('getItems')->andReturn($rows);

    $factory = Mockery::mock(CollectionFactory::class);
    $factory->shouldReceive('create')->andReturn($collection);

    return $factory;
}

function createCacheTypeList(): TypeListInterface
{
    $cacheTypeList = Mockery::mock(TypeListInterface::class);
    $cacheTypeList->shouldReceive('cleanType')->withAnyArgs()->andReturnNull();
    return $cacheTypeList;
}

function createRandomBytesGenerator(?string $bytes = null): RandomBytesGeneratorInterface
{
    $generator = Mockery::mock(RandomBytesGeneratorInterface::class);
    $generator->shouldReceive('generate')
        ->andReturnUsing(static function (int $length = 32) use ($bytes): string {
            return $bytes !== null ? $bytes : random_bytes($length);
        });
    return $generator;
}

it('returns a 64-character lowercase hex token and persists its SHA-256 hash', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')
        ->once()
        ->withArgs(function (string $path, string $value, string $scope, int $scopeId): bool {
            return $path === TokenService::CONFIG_PATH
                && $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                && $scopeId === 0
                && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
        });

    $service = new TokenService($writer, createCollectionFactory(), createCacheTypeList(), createRandomBytesGenerator());

    $token = $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);

    expect($token)->toMatch('/^[a-f0-9]{64}$/');
});

it('persists the hash whose value equals SHA-256 of the returned plaintext', function () {
    $captured = null;

    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')
        ->once()
        ->andReturnUsing(function (string $path, string $value) use (&$captured): void {
            $captured = $value;
        });

    $service = new TokenService($writer, createCollectionFactory(), createCacheTypeList(), createRandomBytesGenerator());

    $token = $service->generateForScope(ScopeInterface::SCOPE_WEBSITES, 7);

    expect($captured)->toBe(hash('sha256', $token));
});

it('forces scopeId to 0 for default scope even when caller passes a non-zero value', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')
        ->once()
        ->withArgs(function (string $path, string $value, string $scope, int $scopeId): bool {
            return $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $scopeId === 0;
        });

    $service = new TokenService($writer, createCollectionFactory(), createCacheTypeList(), createRandomBytesGenerator());

    $service->generateForScope(ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 99);
});

it('throws InputException for unsupported scope (e.g. group)', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldNotReceive('save');

    $service = new TokenService($writer, createCollectionFactory(), createCacheTypeList(), createRandomBytesGenerator());

    $service->generateForScope('group', 1);
})->throws(InputException::class);

it('throws AlreadyExistsException and does not persist when hash already exists at another scope', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldNotReceive('save');

    $factory = createCollectionFactory([
        ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scope_id' => 1],
    ]);

    $service = new TokenService($writer, $factory, createCacheTypeList(), createRandomBytesGenerator());

    $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);
})->throws(AlreadyExistsException::class);

it('does not throw when the only existing row at the same hash is the current coordinate (idempotent self-overwrite)', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')->once();

    $factory = createCollectionFactory([
        ['scope' => ScopeInterface::SCOPE_STORES, 'scope_id' => 5],
    ]);

    $service = new TokenService($writer, $factory, createCacheTypeList(), createRandomBytesGenerator());

    $service->generateForScope(ScopeInterface::SCOPE_STORES, 5);
});

it('flushes the config cache type exactly once after a successful save', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')->once();

    $cacheTypeList = Mockery::mock(TypeListInterface::class);
    $cacheTypeList->shouldReceive('cleanType')->once()->with('config');

    $service = new TokenService($writer, createCollectionFactory(), $cacheTypeList, createRandomBytesGenerator());

    $service->generateForScope(ScopeInterface::SCOPE_WEBSITES, 1);
});

it('does not flush the cache or persist when the hash uniqueness check rejects the write', function () {
    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldNotReceive('save');

    $cacheTypeList = Mockery::mock(TypeListInterface::class);
    $cacheTypeList->shouldNotReceive('cleanType');

    $factory = createCollectionFactory([
        ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0],
    ]);

    $service = new TokenService($writer, $factory, $cacheTypeList, createRandomBytesGenerator());

    try {
        $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);
        expect(false)->toBeTrue();
    } catch (AlreadyExistsException $e) {
        expect($e->getMessage())->toContain('already exists');
    }
});

it('uses the injected RandomBytesGenerator so the persisted hash is deterministic under the seam', function () {
    $fixedBytes   = str_repeat("\x00", 32);
    $expectedHash = hash('sha256', bin2hex($fixedBytes));

    $captured = null;

    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldReceive('save')
        ->once()
        ->andReturnUsing(function (string $path, string $value) use (&$captured): void {
            $captured = $value;
        });

    $service = new TokenService(
        $writer,
        createCollectionFactory(),
        createCacheTypeList(),
        createRandomBytesGenerator($fixedBytes)
    );

    $token = $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);

    expect($token)->toBe(bin2hex($fixedBytes));
    expect($captured)->toBe($expectedHash);
});

it('rejects a forced hash collision via the seam without writing or flushing the cache', function () {
    $fixedBytes    = str_repeat("\x01", 32);
    $collidingHash = hash('sha256', bin2hex($fixedBytes));

    $writer = Mockery::mock(WriterInterface::class);
    $writer->shouldNotReceive('save');

    $cacheTypeList = Mockery::mock(TypeListInterface::class);
    $cacheTypeList->shouldNotReceive('cleanType');

    $factory = createCollectionFactory([
        ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scope_id' => 0, 'value' => $collidingHash],
    ]);

    $service = new TokenService($writer, $factory, $cacheTypeList, createRandomBytesGenerator($fixedBytes));

    $service->generateForScope(ScopeInterface::SCOPE_STORES, 2);
})->throws(AlreadyExistsException::class);
