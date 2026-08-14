<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

/**
 * State config in terms of what a scope *resolves to*, not where the row
 * lives. Magento resolves `core_config_data` through a default → website →
 * store fallback (Store\Model\Config\Processor\Fallback), and that cascade is
 * Magento's, not ours — modelling it here would only assert this mock's own
 * logic. So:
 *
 * - $values: paths every store resolves to (i.e. inherited, whatever tier set them)
 * - $perStoreValues: [storeId][path] where one store resolves to something else
 * - $carrierValues: [carrier][code] returned by getCarrierConfig()
 *
 * Anything unlisted is "not configured" (null / []).
 */
function createConfig(array $values = [], array $carrierValues = [], array $perStoreValues = []): Config
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

    return $config;
}
