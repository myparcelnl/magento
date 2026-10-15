<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\UserAgent;

/**
 * States config as what a scope *resolves to*, not where the row lives.
 * Magento's default → website → store cascade is Magento's, so modelling it
 * here would only assert this mock.
 *
 * - $values: paths every store resolves to
 * - $perStoreValues: [storeId][path] where one store resolves to something else
 * - $carrierValues: [carrier][code] for getCarrierConfig()
 * - $scopedValues: [scopeName][scopeId][fullPath] for getScopedConfig(), which takes whole paths
 *   rather than general-settings codes and therefore has its own key space
 *
 * Anything unlisted is "not configured" (null / []).
 */
function createConfig(
    array $values = [],
    array $carrierValues = [],
    array $perStoreValues = [],
    array $scopedValues = []
): Config
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')
        ->andReturnUsing(function (string $code, $storeId = null) use ($values, $perStoreValues) {
            if (null !== $storeId && isset($perStoreValues[$storeId][$code])) {
                return $perStoreValues[$storeId][$code];
            }

            return $values[$code] ?? null;
        });
    $config->shouldReceive('getCarrierConfig')
        ->andReturnUsing(function (string $carrier, string $code = '') use ($carrierValues) {
            return $carrierValues[$carrier][$code] ?? [];
        });
    $config->shouldReceive('getScopedConfig')
        ->andReturnUsing(function (string $path, string $scopeName = 'default', $scopeId = null) use ($scopedValues) {
            return $scopedValues[$scopeName][(int) $scopeId][$path] ?? null;
        });
    // Kept in step with the real method rather than stubbed to a constant: both export paths chunk
    // by it, and a test that sets print/export_chunk_size means the chunking, not the reading.
    $config->shouldReceive('getExportChunkSize')
        ->andReturnUsing(function ($storeId = null) use ($values, $perStoreValues): int {
            $configured = null !== $storeId && isset($perStoreValues[$storeId]['print/export_chunk_size'])
                ? $perStoreValues[$storeId]['print/export_chunk_size']
                : ($values['print/export_chunk_size'] ?? null);

            if (! is_numeric($configured)) {
                return Config::DEFAULT_EXPORT_CHUNK_SIZE;
            }

            $size = (int) $configured;

            return 1 <= $size && $size <= 100 ? $size : Config::DEFAULT_EXPORT_CHUNK_SIZE;
        });

    // The module version, which UserAgent reads. byDefault() so a test can name its own.
    $config->shouldReceive('getVersion')->andReturn('5.9.0')->byDefault();

    return $config;
}

/**
 * The real UserAgent over two stubbed version sources — it is a pure mapping, so mocking it would
 * assert the mock. The two arguments are deliberately different values: a test that swaps them
 * should fail.
 */
function createUserAgent(string $magentoVersion = '2.4.6', string $moduleVersion = '5.9.0'): UserAgent
{
    $productMetadata = Mockery::mock(ProductMetadataInterface::class);
    $productMetadata->shouldReceive('getVersion')->andReturn($magentoVersion);

    // Overrides createConfig()'s byDefault() version.
    $config = createConfig();
    $config->shouldReceive('getVersion')->andReturn($moduleVersion);

    return new UserAgent($config, $productMetadata);
}

/**
 * A ScopeConfigInterface answering out of a path map. Anything unlisted is an unset row.
 *
 * $reader replaces the lookup for the rare caller that has to see the scope and scope id too; it
 * receives getValue()'s arguments unchanged.
 *
 * @param array<string,mixed> $rowsByPath
 */
function mockScopeConfig(array $rowsByPath = [], ?callable $reader = null): ScopeConfigInterface
{
    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        $reader ?? static fn (string $path) => $rowsByPath[$path] ?? null
    );

    return $scopeConfig;
}

/**
 * A Config that answers every typed getter with a harmless value.
 *
 * Checkout reads a dozen settings per carrier. A test about capabilities wants none of them to be
 * the reason an option disappeared.
 */
function createPermissiveConfig(): Config
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getBoolConfig')->andReturn(true)->byDefault();
    $config->shouldReceive('getFloatConfig')->andReturn(0.0)->byDefault();
    $config->shouldReceive('getConfigValue')->andReturn(null)->byDefault();
    $config->shouldReceive('getTimeConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getStringConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getIntegerConfig')->andReturn(0)->byDefault();

    return $config;
}
