<?php

declare(strict_types=1);

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
