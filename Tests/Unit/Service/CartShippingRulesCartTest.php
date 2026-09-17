<?php

declare(strict_types=1);

const CART_RULES_PRIORITY_PATH = 'myparcelnl_magento_postnl_settings/mailbox/priority_delivery_active';
const CART_RULES_LOCKERS_PATH  = 'myparcelnl_magento_general/shipping_methods/exclude_parcel_lockers';
const CART_RULES_CARRIER_PATH  = 'myparcelnl_magento_postnl_settings/';

it('offers priority delivery when the carrier setting is on', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '1'], [1 => [], 2 => []]);

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('offers priority delivery on an empty cart when the carrier setting is on', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '1']);

    expect($rules->allowsPriorityDelivery([], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('offers priority delivery when one product asks for it and the setting is off', function () {
    $rules = createCartShippingRules(
        [CART_RULES_PRIORITY_PATH => '0'],
        [1 => [], 2 => ['myparcel_priority_delivery' => '1']]
    );

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('refuses priority delivery when neither the setting nor any product asks for it', function () {
    $rules = createCartShippingRules(
        [CART_RULES_PRIORITY_PATH => '0'],
        [1 => [], 2 => ['myparcel_priority_delivery' => '0']]
    );

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], CART_RULES_CARRIER_PATH))->toBeFalse();
});

it('refuses priority delivery on an empty cart with the setting off', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '0']);

    expect($rules->allowsPriorityDelivery([], CART_RULES_CARRIER_PATH))->toBeFalse();
});

it('hides delivery options when a product says so', function () {
    $rules = createCartShippingRules([], [1 => [], 2 => ['myparcel_disable_checkout' => '1']]);

    expect($rules->hidesDeliveryOptions([quoteItemFor(1), quoteItemFor(2)]))->toBeTrue();
});

it('does not let one cart that hides delivery options silence the next', function () {
    // The predecessor set a public flag and never reset it, so on a shared instance the second cart
    // inherited the first cart's answer.
    $rules = createCartShippingRules([], [1 => ['myparcel_disable_checkout' => '1'], 2 => []]);

    expect($rules->hidesDeliveryOptions([quoteItemFor(1)]))->toBeTrue()
        ->and($rules->hidesDeliveryOptions([quoteItemFor(2)]))->toBeFalse();
});

it('takes the longest drop off delay in the cart', function () {
    $rules = createCartShippingRules([], [
        1 => ['myparcel_dropoff_delay' => '2'],
        2 => ['myparcel_dropoff_delay' => '5'],
    ]);

    expect($rules->dropOffDelay([quoteItemFor(1), quoteItemFor(2)]))->toBe(5);
});

it('answers no drop off delay rather than zero', function () {
    $rules = createCartShippingRules([], [1 => ['myparcel_dropoff_delay' => '0']]);

    expect($rules->dropOffDelay([quoteItemFor(1)]))->toBeNull();
});

it('excludes parcel lockers on the general setting alone', function () {
    $rules = createCartShippingRules([CART_RULES_LOCKERS_PATH => '1']);

    expect($rules->excludesParcelLockers([], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('excludes parcel lockers when a product says so', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_exclude_parcel_lockers' => '1']]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('excludes parcel lockers for 18+ goods', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '1'],
        [1 => []]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], CART_RULES_CARRIER_PATH))->toBeTrue();
});

it('allows parcel lockers when nothing excludes them', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => []]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], CART_RULES_CARRIER_PATH))->toBeFalse();
});

it('allows parcel lockers when the read throws, rather than hiding pickup over a config error', function () {
    $rules = createCartShippingRules([], [1 => []]);

    expect($rules->excludesParcelLockers([Mockery::mock()], CART_RULES_CARRIER_PATH))->toBeFalse();
});
