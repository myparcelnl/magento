<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;

const CART_RULES_AGE_CHECK_PATH = 'myparcelnl_magento_postnl_settings/default_options/age_check_active';

/**
 * A forced option is one the order gets whatever the shopper picks: set on a product, or on in the
 * carrier's export settings. Either tier forces it; a tier with no value counts as off.
 *
 * @param array<string,mixed>            $config config path => value
 * @param array<int,array<string,string>> $rows   product id => myparcel_* attribute => value
 */
function createCartShippingRules(array $config, array $rows = [], ?array &$loads = null): CartShippingRules
{
    $configMock = Mockery::mock(Config::class);
    $configMock->shouldReceive('getConfigValue')
               ->andReturnUsing(static fn(string $path, $storeId = null) => $config[$path] ?? null);
    $configMock->shouldReceive('getGeneralConfig')
               ->andReturnUsing(static fn(string $code = '', $storeId = null) => $config[Config::XML_PATH_GENERAL . $code] ?? null);

    return new CartShippingRules($configMock, productAttributesFor($rows, $loads));
}
