<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\DeliveryTypeTransformer;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\DeliveryType as OrderApiDeliveryType;

it('returns null for null input', function () {
    expect((new DeliveryTypeTransformer())->transform(null))->toBeNull();
});

it('returns an Order API enum value unchanged', function () {
    expect((new DeliveryTypeTransformer())->transform(OrderApiDeliveryType::STANDARD_DELIVERY))
        ->toBe(OrderApiDeliveryType::STANDARD_DELIVERY);
});

it('maps short delivery type names to the Order API enum', function () {
    $t = new DeliveryTypeTransformer();

    expect($t->transform('standard'))->toBe(OrderApiDeliveryType::STANDARD_DELIVERY);
    expect($t->transform('morning'))->toBe(OrderApiDeliveryType::MORNING_DELIVERY);
    expect($t->transform('evening'))->toBe(OrderApiDeliveryType::EVENING_DELIVERY);
    expect($t->transform('pickup'))->toBe(OrderApiDeliveryType::PICKUP_DELIVERY);
    expect($t->transform('express'))->toBe(OrderApiDeliveryType::EXPRESS_DELIVERY);
});

it('returns null for unknown delivery types', function () {
    expect((new DeliveryTypeTransformer())->transform('does_not_exist'))->toBeNull();
});
