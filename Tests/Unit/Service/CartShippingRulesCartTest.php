<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;

const CART_RULES_PRIORITY_PATH = 'myparcelnl_magento_postnl_settings/mailbox/priority_delivery_active';
const CART_RULES_LOCKERS_PATH  = Config::XML_PATH_GENERAL . 'shipping_methods/exclude_parcel_lockers';

it('offers priority delivery when the carrier setting is on', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '1'], [1 => [], 2 => []]);

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], Config::carrierPath('postnl')))->toBeTrue();
});

it('offers priority delivery on an empty cart when the carrier setting is on', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '1']);

    expect($rules->allowsPriorityDelivery([], Config::carrierPath('postnl')))->toBeTrue();
});

it('offers priority delivery when one product asks for it and the setting is off', function () {
    $rules = createCartShippingRules(
        [CART_RULES_PRIORITY_PATH => '0'],
        [1 => [], 2 => ['myparcel_priority_delivery' => '1']]
    );

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], Config::carrierPath('postnl')))->toBeTrue();
});

it('refuses priority delivery when neither the setting nor any product asks for it', function () {
    $rules = createCartShippingRules(
        [CART_RULES_PRIORITY_PATH => '0'],
        [1 => [], 2 => ['myparcel_priority_delivery' => '0']]
    );

    expect($rules->allowsPriorityDelivery([quoteItemFor(1), quoteItemFor(2)], Config::carrierPath('postnl')))->toBeFalse();
});

it('refuses priority delivery on an empty cart with the setting off', function () {
    $rules = createCartShippingRules([CART_RULES_PRIORITY_PATH => '0']);

    expect($rules->allowsPriorityDelivery([], Config::carrierPath('postnl')))->toBeFalse();
});

it('hides delivery options when a product says so', function () {
    $rules = createCartShippingRules([], [1 => [], 2 => ['myparcel_disable_checkout' => '1']]);

    expect($rules->hidesDeliveryOptions([quoteItemFor(1), quoteItemFor(2)]))->toBeTrue();
});

it('does not let one cart that hides delivery options silence the next', function () {
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

    expect($rules->excludesParcelLockers([], Config::carrierPath('postnl')))->toBeTrue();
});

it('excludes parcel lockers when a product says so', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => ['myparcel_exclude_parcel_lockers' => '1']]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], Config::carrierPath('postnl')))->toBeTrue();
});

it('excludes parcel lockers when the carrier forces the age check', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '1'],
        [1 => []]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], Config::carrierPath('postnl')))->toBeTrue();
});

it('allows parcel lockers when nothing excludes them', function () {
    $rules = createCartShippingRules(
        [CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '0'],
        [1 => []]
    );

    expect($rules->excludesParcelLockers([quoteItemFor(1)], Config::carrierPath('postnl')))->toBeFalse();
});

it('skips the age check rule when there is no carrier to read it from', function () {
    // The checkout passes null with no active carrier: only the general and product rules apply.
    $rules = createCartShippingRules([CART_RULES_LOCKERS_PATH => '0', CART_RULES_AGE_CHECK_PATH => '1'], [1 => []]);

    expect($rules->excludesParcelLockers([quoteItemFor(1)], null))->toBeFalse();
});

it('keeps the general setting and the product rule with no carrier', function () {
    $general = createCartShippingRules([CART_RULES_LOCKERS_PATH => '1']);
    $product = createCartShippingRules([CART_RULES_LOCKERS_PATH => '0'], [1 => ['myparcel_exclude_parcel_lockers' => '1']]);

    expect($general->excludesParcelLockers([], null))->toBeTrue()
        ->and($product->excludesParcelLockers([quoteItemFor(1)], null))->toBeTrue();
});

it('allows parcel lockers when reading the cart throws', function () {
    $rules = createCartShippingRules([], [1 => []]);

    expect($rules->excludesParcelLockers([Mockery::mock()], Config::carrierPath('postnl')))->toBeFalse();
});
