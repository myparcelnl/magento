<?php

declare(strict_types=1);

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Tests\Helpers\MyParcelTokenLifecycleHarness;
use Psr\Log\LoggerInterface;

/**
 * ScopeConfigInterface double answering from an explicit coordinate map, so a test can say "this key
 * is configured only at store 2" or "this key exists only in env.php".
 *
 * Coordinate keys are 'default', 'websites:<id>' and 'stores:<id>'.
 *
 * @param array<string, string> $apiKeyByCoordinate
 */
function accountSettingsScopeConfig(array $apiKeyByCoordinate): ScopeConfigInterface
{
    $config = Mockery::mock(ScopeConfigInterface::class);
    $config->shouldReceive('getValue')->andReturnUsing(
        function (string $path, string $scope = 'default', $scopeId = null) use ($apiKeyByCoordinate) {
            if (Config::XML_PATH_API_KEY !== $path) {
                return null;
            }

            $coordinate = 'default' === $scope ? 'default' : $scope . ':' . (int) $scopeId;

            return $apiKeyByCoordinate[$coordinate] ?? null;
        }
    );

    return $config;
}

function countingCacheTypeList(?int &$cleanCalls): TypeListInterface
{
    $cleanCalls ??= 0;

    $cache = Mockery::mock(TypeListInterface::class);
    $cache->shouldReceive('cleanType')->andReturnUsing(function () use (&$cleanCalls): void {
        $cleanCalls++;
    });

    return $cache;
}

/**
 * @param array<string, string>                     $apiKeyByCoordinate
 * @param array<int, array{id: int, websiteId: int}> $stores
 */
function accountSettingsMaintenance(
    MyParcelTokenLifecycleHarness $harness,
    array                         $apiKeyByCoordinate,
    array                         $stores = [],
    ?TypeListInterface            $cacheTypeList = null
): Maintenance {
    return new Maintenance(
        $harness->collectionFactory(),
        $harness->writer(),
        $cacheTypeList ?? $harness->cacheTypeList(),
        accountSettingsScopeConfig($apiKeyByCoordinate),
        mockStoreManager($stores),
        new Fingerprint(),
        Mockery::spy(LoggerInterface::class)
    );
}

function settingsPathFor(string $apiKey): string
{
    return Config::XML_PATH_ACCOUNT_SETTINGS . (new Fingerprint())->of($apiKey);
}

function rowAt(MyParcelTokenLifecycleHarness $harness, string $path): ?array
{
    foreach ($harness->rows as $row) {
        if ($row['path'] === $path) {
            return $row;
        }
    }

    return null;
}

it('moves a legacy plaintext-suffixed row to its hashed path, preserving value and scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'live-key-alpha';

    $harness->save(Config::XML_PATH_API_KEY, $apiKey, 'default', 0);
    $harness->save(Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey, '{"shop":1}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => $apiKey])->reconcile();

    $paths = array_column($harness->rows, 'path');

    expect($paths)->toContain(settingsPathFor($apiKey))
        ->and($paths)->not->toContain(Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey);

    $migrated = rowAt($harness, settingsPathFor($apiKey));

    expect($migrated['value'])->toBe('{"shop":1}')
        ->and($migrated['scope'])->toBe('default')
        ->and($migrated['scope_id'])->toBe(0);
});

it('preserves a non-default scope coordinate when migrating', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'website-scoped-key';

    $harness->save(Config::XML_PATH_API_KEY, $apiKey, ScopeInterface::SCOPE_WEBSITES, 3);
    $harness->save(Config::XML_PATH_ACCOUNT_SETTINGS . $apiKey, '{"shop":7}', ScopeInterface::SCOPE_WEBSITES, 3);

    accountSettingsMaintenance(
        $harness,
        [ScopeInterface::SCOPE_WEBSITES . ':3' => $apiKey],
        [['id' => 5, 'websiteId' => 3]]
    )->reconcile();

    $migrated = rowAt($harness, settingsPathFor($apiKey));

    expect($migrated)->not->toBeNull()
        ->and($migrated['scope'])->toBe(ScopeInterface::SCOPE_WEBSITES)
        ->and($migrated['scope_id'])->toBe(3);
});

it('keeps a row whose api key is configured only at store-view scope', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'store-only-key';

    $harness->save(Config::XML_PATH_API_KEY, $apiKey, ScopeInterface::SCOPE_STORES, 2);
    $harness->save(settingsPathFor($apiKey), '{"shop":2}', 'default', 0);

    accountSettingsMaintenance(
        $harness,
        [ScopeInterface::SCOPE_STORES . ':2' => $apiKey],
        [['id' => 2, 'websiteId' => 1]]
    )->reconcile();

    expect(array_column($harness->rows, 'path'))->toContain(settingsPathFor($apiKey));
});

it('keeps a row whose api key exists only in env.php and has no config row', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'env-locked-key';

    // Deliberately no api/key row: config:set --lock-env writes to app/etc/env.php, not core_config_data.
    $harness->save(settingsPathFor($apiKey), '{"shop":3}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => $apiKey])->reconcile();

    expect(array_column($harness->rows, 'path'))->toContain(settingsPathFor($apiKey));
});

it('deletes a row whose api key is not configured anywhere', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $live    = 'still-configured';
    $dead    = 'long-gone';

    $harness->save(Config::XML_PATH_API_KEY, $live, 'default', 0);
    $harness->save(settingsPathFor($live), '{"shop":1}', 'default', 0);
    $harness->save(settingsPathFor($dead), '{"shop":9}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => $live])->reconcile();

    $paths = array_column($harness->rows, 'path');

    expect($paths)->toContain(settingsPathFor($live))
        ->and($paths)->not->toContain(settingsPathFor($dead));
});

it('deletes nothing when no api key is configured anywhere', function () {
    $harness = new MyParcelTokenLifecycleHarness();

    $harness->save(settingsPathFor('some-key'), '{"shop":1}', 'default', 0);
    $harness->save(Config::XML_PATH_ACCOUNT_SETTINGS . 'legacy-plaintext', '{"shop":2}', 'default', 0);

    accountSettingsMaintenance($harness, [])->reconcile();

    expect($harness->rows)->toHaveCount(2);
});

it('changes nothing on a second pass', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $live    = 'idempotent-key';
    $dead    = 'removed-key';

    $harness->save(Config::XML_PATH_API_KEY, $live, 'default', 0);
    $harness->save(Config::XML_PATH_ACCOUNT_SETTINGS . $live, '{"shop":1}', 'default', 0);
    $harness->save(settingsPathFor($dead), '{"shop":9}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => $live])->reconcile();
    $afterFirstPass = $harness->rows;

    accountSettingsMaintenance($harness, ['default' => $live])->reconcile();

    expect($harness->rows)->toEqual($afterFirstPass);
});

it('flushes the config cache when something changed', function () {
    $harness    = new MyParcelTokenLifecycleHarness();
    $cleanCalls = null;

    $harness->save(Config::XML_PATH_API_KEY, 'a-key', 'default', 0);
    $harness->save(settingsPathFor('vanished-key'), '{"shop":9}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => 'a-key'], [], countingCacheTypeList($cleanCalls))
        ->reconcile();

    expect($cleanCalls)->toBe(1);
});

it('does not flush the config cache when nothing changed', function () {
    $harness    = new MyParcelTokenLifecycleHarness();
    $cleanCalls = null;

    $harness->save(Config::XML_PATH_API_KEY, 'a-key', 'default', 0);
    $harness->save(settingsPathFor('a-key'), '{"shop":1}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => 'a-key'], [], countingCacheTypeList($cleanCalls))
        ->reconcile();

    expect($cleanCalls)->toBe(0);
});

it('ignores config rows belonging to other settings', function () {
    $harness = new MyParcelTokenLifecycleHarness();
    $apiKey  = 'a-key';

    $harness->save(Config::XML_PATH_API_KEY, $apiKey, 'default', 0);
    $harness->save('myparcelnl_magento_general/print/paper_type', 'A4', 'default', 0);
    $harness->save(settingsPathFor($apiKey), '{"shop":1}', 'default', 0);

    accountSettingsMaintenance($harness, ['default' => $apiKey])->reconcile();

    expect(array_column($harness->rows, 'path'))->toContain('myparcelnl_magento_general/print/paper_type');
});
