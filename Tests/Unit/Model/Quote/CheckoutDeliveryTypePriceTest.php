<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Quote\Checkout;

/** @param array<string, float> $basePrices */
function deliveryTypePrice(array $basePrices, string $deliveryType, float $fee, bool $showTotalPrice): float
{
    return invokePrivateMethod(newInstanceWithoutConstructor(Checkout::class), 'deliveryTypePrice', [$basePrices, $deliveryType, $fee, $showTotalPrice]);
}

it('shows the base price of the delivery type plus its fee as total', function () {
    expect(deliveryTypePrice(['standard' => 6.95, 'evening' => 1.0], 'evening', 2.5, true))->toBe(3.5);
});

it('shows the difference with standard delivery as surcharge', function () {
    expect(deliveryTypePrice(['standard' => 6.95, 'evening' => 1.0], 'evening', 2.5, false))->toEqualWithDelta(-3.45, 0.0001)
        ->and(deliveryTypePrice(['standard' => 6.95, 'evening' => 6.95], 'evening', 2.5, false))->toBe(2.5);
});

it('never shows a negative total', function () {
    expect(deliveryTypePrice(['standard' => 6.95, 'morning' => 1.0], 'morning', -3.0, true))->toBe(0.0)
        ->and(deliveryTypePrice(['standard' => 6.95, 'morning' => 1.0], 'morning', -3.0, false))->toBe(-6.95);
});

it('builds the widget price key from the delivery type', function (string $deliveryType, string $priceKey) {
    expect(invokePrivateMethod(newInstanceWithoutConstructor(Checkout::class), 'getPriceKeyFromDeliveryType', [$deliveryType]))->toBe($priceKey);
})->with([
    ['standard', 'priceStandardDelivery'],
    ['morning', 'priceMorningDelivery'],
    ['evening', 'priceEveningDelivery'],
    ['pickup', 'pricePickup'],
    ['same_day', 'priceSameDayDelivery'],
]);
