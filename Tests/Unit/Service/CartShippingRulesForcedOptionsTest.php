<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Config;

/**
 * A forced option is one the order gets whatever the shopper picks: set on a product, or on in the
 * carrier's export settings. Both tiers are always read, because a future entry in
 * ShipmentOption::LIMIT_PACKAGE_TYPE may have only one of them.
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

const CART_RULES_AGE_CHECK_PATH = 'myparcelnl_magento_postnl_settings/default_options/age_check_active';
const POSTNL_PATH           = 'myparcelnl_magento_postnl_settings/';

it('finds nothing forced when neither the product nor the export setting says so', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0'], [1 => []]);

    expect($rules->forcedLimitingOptions([quoteItemFor()], POSTNL_PATH))->toBe([]);
});

it('reads a forced option from the export settings', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '1']);

    expect($rules->forcedLimitingOptions([], POSTNL_PATH))->toBe([ShipmentOption::AGE_CHECK]);
});

it('reads a forced option from a product, even with the export setting off', function () {
    $rules = createCartShippingRules(
        [CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_age_check' => '1']]
    );

    expect($rules->forcedLimitingOptions([quoteItemFor()], POSTNL_PATH))->toBe([ShipmentOption::AGE_CHECK]);
});

it('treats a config path that does not exist as not forced', function () {
    // A future limiting option may have no export setting for a given carrier. Absent is "no",
    // never an error.
    $rules = createCartShippingRules([]);

    expect($rules->forcedLimitingOptions([], 'myparcelnl_magento_dpd_settings/'))->toBe([]);
});

it('does not read a threshold or a stray string as on', function () {
    // large_format_active ships as the literal 'No' and can hold 'price'. Both cast to true, which
    // is why the read compares against '1'.
    foreach (['No', 'price', '0', ''] as $value) {
        $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => $value]);

        expect($rules->forcedLimitingOptions([], POSTNL_PATH))
            ->toBe([], sprintf('"%s" was read as forced', $value));
    }
});

it('keeps forcesAgeCheck answering the same question', function () {
    $forced = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '1']);
    $unset  = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0']);

    expect($forced->forcesAgeCheck([], POSTNL_PATH))->toBeTrue()
        ->and($unset->forcesAgeCheck([], POSTNL_PATH))->toBeFalse();
});

/**
 * Checkout asks the same question three times per carrier — the age check, the forced options and
 * the package type all reduce to this — and each pass used to run an EAV query per quote item.
 */
it('loads the product batch once however often it is asked', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0'], [1 => [], 2 => []], $loads);

    $products = [quoteItemFor(1), quoteItemFor(2)];

    foreach ([1, 2, 3] as $ignored) {
        $rules->forcedLimitingOptions($products, POSTNL_PATH);
    }

    expect($loads)->toHaveCount(1)
        ->and($loads[0])->toBe([1, 2]);
});

it('answers the same for a second cart, so nothing carried over from the first', function () {
    $rules = createCartShippingRules(
        [CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_age_check' => '1'], 2 => []]
    );

    expect($rules->forcedLimitingOptions([quoteItemFor(1)], POSTNL_PATH))->toBe([ShipmentOption::AGE_CHECK])
        ->and($rules->forcedLimitingOptions([quoteItemFor(2)], POSTNL_PATH))->toBe([]);
});
