<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
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

/**
 * @param array<int, array{0: float, 1: float, 2: float}> $rows row total, row total incl. tax, discount per item
 * @param array<string, string|null> $taxConfig by path
 */
function orderAmountFor(array $rows, array $taxConfig = []): float
{
    $items = array_map(static function (array $row) {
        $item = Mockery::mock(Item::class);
        $item->shouldReceive('getRowTotal')->andReturn($row[0]);
        $item->shouldReceive('getRowTotalInclTax')->andReturn($row[1]);
        $item->shouldReceive('getDiscountAmount')->andReturn($row[2]);

        return $item;
    }, $rows);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getStoreId')->andReturn(1);
    $quote->shouldReceive('getAllVisibleItems')->andReturn($items);

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function (string $path) use ($taxConfig) {
        return $taxConfig[$path] ?? null;
    });

    $deliveryCosts = newInstanceWithoutConstructor(DeliveryCosts::class);
    setPrivateProperty($deliveryCosts, 'config', $config);

    return invokePrivateMethod($deliveryCosts, 'getOrderAmount', [$quote]);
}

it('takes the subtotal including tax when there is no discount', function () {
    expect(orderAmountFor([[45.39, 54.92, 0.0], [10.0, 10.9, 0.0]]))->toEqualWithDelta(65.82, 0.001);
});

it('raises a discount excluding tax by the tax rate of its item', function () {
    // The cart from the test environment: 20% off 45.39 excl. tax is 9.07, which also lowers the 21% tax.
    // Subtracting 9.07 from 54.92 would give 45.85; 9.07 * 1.21 = 10.97 gives the real 43.95.
    expect(orderAmountFor([[45.39, 54.92, 9.07]]))->toEqualWithDelta(43.945, 0.001);
});

it('raises the discount per item, each by its own tax rate', function () {
    // 21% and 9% tax, 10 off each excl. tax: (121 - 12.10) + (109 - 10.90)
    expect(orderAmountFor([[100.0, 121.0, 10.0], [100.0, 109.0, 10.0]]))->toEqualWithDelta(207.0, 0.001);
});

it('subtracts a discount that already includes tax as it is', function () {
    expect(orderAmountFor([[45.39, 54.92, 10.98]], ['tax/calculation/discount_tax' => '1']))
        ->toEqualWithDelta(43.94, 0.001);
});

it('subtracts the discount as it is when tax is calculated before the discount', function () {
    // the discount leaves the tax alone, so the full tax stays on the order
    expect(orderAmountFor([[45.39, 54.92, 9.07]], ['tax/calculation/apply_after_discount' => '0']))
        ->toEqualWithDelta(45.85, 0.001);
});

it('never takes a negative order amount', function () {
    expect(orderAmountFor([[10.0, 12.1, 15.0]]))->toBe(0.0);
});

it('takes 0 for a quote without items', function () {
    expect(orderAmountFor([]))->toBe(0.0);
});

it('sums the order amount once per quote and keeps quotes apart', function () {
    $itemFor = static function (float $rowTotal, float $inclTax): Item {
        $item = Mockery::mock(Item::class);
        $item->shouldReceive('getRowTotal')->andReturn($rowTotal);
        $item->shouldReceive('getRowTotalInclTax')->andReturn($inclTax);
        $item->shouldReceive('getDiscountAmount')->andReturn(0.0);

        return $item;
    };
    // once(): a second computation over the items fails the test
    $quoteFor = static function (Item $item): Quote {
        $quote = Mockery::mock(Quote::class);
        $quote->shouldReceive('getStoreId')->andReturn(1);
        $quote->shouldReceive('getAllVisibleItems')->once()->andReturn([$item]);

        return $quote;
    };

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnNull();

    $deliveryCosts = newInstanceWithoutConstructor(DeliveryCosts::class);
    setPrivateProperty($deliveryCosts, 'config', $config);

    $one = $quoteFor($itemFor(100.0, 121.0));
    $two = $quoteFor($itemFor(50.0, 54.5));

    expect(invokePrivateMethod($deliveryCosts, 'getOrderAmount', [$one]))->toBe(121.0)
        ->and(invokePrivateMethod($deliveryCosts, 'getOrderAmount', [$one]))->toBe(121.0)
        ->and(invokePrivateMethod($deliveryCosts, 'getOrderAmount', [$two]))->toBe(54.5);
});
