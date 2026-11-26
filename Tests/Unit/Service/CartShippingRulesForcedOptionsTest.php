<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;

it('finds nothing forced when neither the product nor the default options say so', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0'], [1 => []]);

    expect($rules->forcedCheckoutOptions([quoteItemFor()], Config::carrierPath('postnl')))->toBe([]);
});

it('reads a forced option from the carrier\'s default options', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '1']);

    expect($rules->forcedCheckoutOptions([], Config::carrierPath('postnl')))->toBe([ShipmentOption::AGE_CHECK]);
});

it('reads a forced option from a product, even with the default option off', function () {
    $rules = createCartShippingRules(
        [CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_age_check' => '1']]
    );

    expect($rules->forcedCheckoutOptions([quoteItemFor()], Config::carrierPath('postnl')))->toBe([ShipmentOption::AGE_CHECK]);
});

it('treats a config path that does not exist as not forced', function () {
    // A future limiting option may have no default option for a given carrier. Absent is "no",
    // never an error.
    $rules = createCartShippingRules([]);

    expect($rules->forcedCheckoutOptions([], 'myparcelnl_magento_dpd_settings/'))->toBe([]);
});

it('does not read a threshold or a stray string as on', function () {
    // large_format_active ships as the literal 'No' and can hold 'price'. Both cast to true, which
    // is why the read compares against '1'.
    foreach (['No', 'price', '0', ''] as $value) {
        $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => $value]);

        expect($rules->forcedCheckoutOptions([], Config::carrierPath('postnl')))
            ->toBe([], sprintf('"%s" was read as forced', $value));
    }
});

it('answers forcesAgeCheck from the default options', function () {
    $forced = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '1']);
    $unset  = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0']);

    expect($forced->forcesAgeCheck([], Config::carrierPath('postnl')))->toBeTrue()
        ->and($unset->forcesAgeCheck([], Config::carrierPath('postnl')))->toBeFalse();
});

/** Checkout asks this several times per carrier; one product load must serve every call. */
it('loads the product batch once however often it is asked', function () {
    $rules = createCartShippingRules([CART_RULES_AGE_CHECK_PATH => '0'], [1 => [], 2 => []], $loads);

    $products = [quoteItemFor(1), quoteItemFor(2)];

    foreach ([1, 2, 3] as $ignored) {
        $rules->forcedCheckoutOptions($products, Config::carrierPath('postnl'));
    }

    expect($loads)->toHaveCount(1)
        ->and($loads[0])->toBe([1, 2]);
});

it('answers the second cart from its own items, so nothing carries over from the first', function () {
    $rules = createCartShippingRules(
        [CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_age_check' => '1'], 2 => []]
    );

    expect($rules->forcedCheckoutOptions([quoteItemFor(1)], Config::carrierPath('postnl')))->toBe([ShipmentOption::AGE_CHECK])
        ->and($rules->forcedCheckoutOptions([quoteItemFor(2)], Config::carrierPath('postnl')))->toBe([]);
});
