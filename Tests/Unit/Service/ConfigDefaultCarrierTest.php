<?php

declare(strict_types=1);

use Magento\Framework\App\Config\ScopeConfigInterface;
use MyParcelNL\Magento\Service\Config;

/**
 * @param array<string, string> $active  carrier => 'delivery', 'pickup' or 'both'
 * @param int[]                 $readStoreIds collects the store id of every config read
 */
function configWithActiveCarriers(array $active, array &$readStoreIds = []): Config
{
    $values = [];
    foreach ($active as $carrier => $types) {
        $path = Config::CARRIERS_XML_PATH_MAP[$carrier];
        $values["{$path}delivery/active"] = in_array($types, ['delivery', 'both'], true) ? '1' : '0';
        $values["{$path}pickup/active"]   = in_array($types, ['pickup', 'both'], true) ? '1' : '0';
    }

    $scopeConfig = Mockery::mock(ScopeConfigInterface::class);
    $scopeConfig->shouldReceive('getValue')->andReturnUsing(
        static function (string $path, string $scope, ?int $storeId) use ($values, &$readStoreIds) {
            $readStoreIds[] = $storeId;

            return $values[$path] ?? null;
        }
    );

    $config = newInstanceWithoutConstructor(Config::class);
    setPrivateProperty($config, 'scopeConfig', $scopeConfig);
    setPrivateProperty($config, 'storeId', 1);

    return $config;
}

it('uses the first active carrier when PostNL is not active', function () {
    $config = configWithActiveCarriers(['dhlforyou' => 'delivery', 'dpd' => 'both']);

    expect($config->getDefaultCarrierName(1))->toBe('dhlforyou');
});

it('keeps PostNL as default when PostNL is active', function () {
    $config = configWithActiveCarriers(['postnl' => 'delivery', 'dhlforyou' => 'both']);

    expect($config->getDefaultCarrierName(1))->toBe('postnl');
});

it('uses the first carrier with pickup active when pickup is required', function () {
    $config = configWithActiveCarriers(['dhlforyou' => 'delivery', 'dpd' => 'both']);

    expect($config->getDefaultCarrierName(1, true))->toBe('dpd');
});

it('falls back to PostNL when no carrier is active', function () {
    expect(configWithActiveCarriers([])->getDefaultCarrierName(1))->toBe('postnl')
        ->and(configWithActiveCarriers(['dhlforyou' => 'delivery'])->getDefaultCarrierName(1, true))->toBe('postnl');
});

it('reads the carrier settings of the given store', function () {
    $readStoreIds = [];
    configWithActiveCarriers(['dhlforyou' => 'delivery'], $readStoreIds)->getDefaultCarrierName(5);

    expect(array_unique($readStoreIds))->toBe([5]);
});

it('lists the active carriers in the fixed carrier order', function () {
    $config = configWithActiveCarriers(['gls' => 'pickup', 'postnl' => 'delivery', 'dpd' => 'delivery']);

    expect($config->getActiveCarriers())->toBe(['postnl', 'dpd', 'gls']);
});
