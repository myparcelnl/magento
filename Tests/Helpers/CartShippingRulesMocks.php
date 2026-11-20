<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;

const CART_RULES_AGE_CHECK_PATH = Config::XML_PATH_POSTNL_SETTINGS . 'default_options/age_check_active';

/**
 * A CartShippingRules over a config map and product attribute rows.
 *
 * A forced option is one the order gets whatever the shopper picks: set on a product, or on in the
 * carrier's default options. Either tier forces it; a tier with no value counts as off.
 *
 * @param array<string,mixed>             $config full config path => value
 * @param array<int,array<string,string>> $rows   product id => myparcel_* attribute => value
 */
function createCartShippingRules(array $config, array $rows = [], ?array &$loads = null): CartShippingRules
{
    return new CartShippingRules(
        configAnswering(static fn(string $path) => $config[$path] ?? null),
        productAttributesFor($rows, $loads)
    );
}
