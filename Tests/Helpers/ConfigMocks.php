<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

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

    return $config;
}
