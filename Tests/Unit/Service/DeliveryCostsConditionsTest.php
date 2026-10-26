<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;

/** @param array<int, array{price: float, conditions: array}> $matrix */
function deliveryCostsWithMatrix(?array $matrix, float $flatRate = 9.99): DeliveryCosts
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('matrix/delivery_costs')
        ->andReturn(null === $matrix ? null : json_encode($matrix));
    $config->shouldReceive('getMagentoCarrierConfig')->with('shipping_cost')->andReturn((string) $flatRate);

    $deliveryCosts = newInstanceWithoutConstructor(DeliveryCosts::class);
    setPrivateProperty($deliveryCosts, 'config', $config);

    return $deliveryCosts;
}

function priceFor(DeliveryCosts $deliveryCosts, float $orderAmount, string $deliveryType = 'standard', string $country = 'NL'): float
{
    return invokePrivateMethod($deliveryCosts, 'calculate', [[
        'carrier_name'  => 'postnl',
        'package_type'  => 1,
        'country'       => $country,
        'weight'        => 1000,
        'order_amount'  => $orderAmount,
        'delivery_type' => $deliveryType,
    ]]);
}

/** Free pickup, € 1 home delivery from € 50, € 6.95 below it: the story's example. */
function merchantConditionsMatrix(): array
{
    return [
        ['name' => 'Base', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'Free pickup', 'price' => 0, 'conditions' => [['type' => 'delivery_type', 'value' => 'pickup']]],
        ['name' => 'Home delivery from 50', 'price' => 1, 'conditions' => [
            ['type' => 'minimum_order_amount', 'value' => 50],
            ['type' => 'delivery_type', 'value' => 'standard'],
        ]],
    ];
}

it('prices each delivery type on its own', function (float $orderAmount, string $deliveryType, float $expected) {
    expect(priceFor(deliveryCostsWithMatrix(merchantConditionsMatrix()), $orderAmount, $deliveryType))->toBe($expected);
})->with([
    'standard below the threshold' => [49.99, 'standard', 6.95],
    'standard at the threshold'    => [50.0, 'standard', 1.0],
    'standard above the threshold' => [120.0, 'standard', 1.0],
    'pickup below the threshold'   => [10.0, 'pickup', 0.0],
    'pickup above the threshold'   => [120.0, 'pickup', 0.0],
    'evening matches neither rule' => [120.0, 'evening', 6.95],
]);

it('applies a maximum order amount below the value only', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 4.95, 'conditions' => []],
        ['price' => 7.95, 'conditions' => [['type' => 'maximum_order_amount', 'value' => 20]]],
    ]);

    expect(priceFor($deliveryCosts, 19.99))->toBe(7.95)
        ->and(priceFor($deliveryCosts, 20.0))->toBe(4.95);
});

it('lets an order amount rule win from a more specific rule without one', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 6.95, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'carrier_name', 'value' => 'postnl'],
            ['type' => 'package_type', 'value' => 1],
        ]],
        ['price' => 0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 50]]],
    ]);

    expect(priceFor($deliveryCosts, 75.0))->toBe(0.0);
});

it('falls back to the flat rate when every rule is ruled out', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 50]]],
    ], 5.5);

    expect(priceFor($deliveryCosts, 10.0))->toBe(5.5);
});

it('prices an existing matrix the same as before', function (float $orderAmount, string $deliveryType) {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['price' => 12.5, 'conditions' => [['type' => 'country_part_of', 'value' => 'EU']]],
        ['price' => 3.5, 'conditions' => [['type' => 'country', 'value' => 'NL'], ['type' => 'maximum_weight', 'value' => 500]]],
    ]);

    expect(priceFor($deliveryCosts, $orderAmount, $deliveryType))->toBe(6.95)
        ->and(priceFor($deliveryCosts, $orderAmount, $deliveryType, 'BE'))->toBe(12.5);
})->with([
    [0.0, 'standard'],
    [250.0, 'pickup'],
]);

it('takes the subtotal after discount including tax as order amount', function () {
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getSubtotalInclTax')->andReturn(121.0);
    $address->shouldReceive('getDiscountAmount')->andReturn(-25.0);
    $address->shouldReceive('getShippingDiscountAmount')->andReturn(5.0);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    // the 5 off shipping is no discount on the order: 121 - (25 - 5)
    expect(invokePrivateMethod(newInstanceWithoutConstructor(DeliveryCosts::class), 'getOrderAmount', [$quote]))->toBe(101.0);
});

it('never takes a negative order amount', function () {
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getSubtotalInclTax')->andReturn(10.0);
    $address->shouldReceive('getDiscountAmount')->andReturn(-15.0);
    $address->shouldReceive('getShippingDiscountAmount')->andReturn(null);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    expect(invokePrivateMethod(newInstanceWithoutConstructor(DeliveryCosts::class), 'getOrderAmount', [$quote]))->toBe(0.0);
});
