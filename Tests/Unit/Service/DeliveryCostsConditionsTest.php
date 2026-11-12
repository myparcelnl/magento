<?php

declare(strict_types=1);

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

function matrixPriceFor(DeliveryCosts $deliveryCosts, float $orderAmount, string $deliveryType = 'standard', string $country = 'NL'): float
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

/** Free pickup, € 1 home delivery from € 50, € 6.95 below it. */
function merchantConditionsMatrix(): array
{
    return [
        ['name' => 'Base', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'Free pickup', 'price' => 0, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'delivery_type', 'value' => 'pickup'],
        ]],
        ['name' => 'Home delivery from 50', 'price' => 1, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'minimum_order_amount', 'value' => 50],
            ['type' => 'delivery_type', 'value' => 'standard'],
        ]],
    ];
}

it('prices each delivery type on its own', function (float $orderAmount, string $deliveryType, float $expected) {
    expect(matrixPriceFor(deliveryCostsWithMatrix(merchantConditionsMatrix()), $orderAmount, $deliveryType))->toBe($expected);
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

    expect(matrixPriceFor($deliveryCosts, 19.99))->toBe(7.95)
        ->and(matrixPriceFor($deliveryCosts, 20.0))->toBe(4.95);
});

it('changes the price of a country only through a rule for that country', function () {
    // free above 50 in NL and BE through one rule; FR stays at 15 until 80. The generic free
    // shipping rule reaches only a country without a price of its own.
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'NL', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'BE', 'price' => 9.95, 'conditions' => [['type' => 'country', 'value' => 'BE']]],
        ['name' => 'FR', 'price' => 15.0, 'conditions' => [['type' => 'country', 'value' => 'FR']]],
        ['name' => 'NL and BE from 50', 'price' => 0, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'country', 'value' => 'BE'],
            ['type' => 'minimum_order_amount', 'value' => 50],
        ]],
        ['name' => 'FR from 80', 'price' => 0, 'conditions' => [
            ['type' => 'country', 'value' => 'FR'],
            ['type' => 'minimum_order_amount', 'value' => 80],
        ]],
        ['name' => 'Elsewhere from 50', 'price' => 0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 50]]],
    ]);

    expect(matrixPriceFor($deliveryCosts, 40.0, 'standard', 'NL'))->toBe(6.95)
        ->and(matrixPriceFor($deliveryCosts, 60.0, 'standard', 'NL'))->toBe(0.0)
        ->and(matrixPriceFor($deliveryCosts, 60.0, 'standard', 'BE'))->toBe(0.0)
        ->and(matrixPriceFor($deliveryCosts, 60.0, 'standard', 'FR'))->toBe(15.0)
        ->and(matrixPriceFor($deliveryCosts, 90.0, 'standard', 'FR'))->toBe(0.0)
        ->and(matrixPriceFor($deliveryCosts, 60.0, 'standard', 'DE'))->toBe(0.0);
});

it('falls back to the flat rate when every rule is ruled out', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 50]]],
    ], 5.5);

    expect(matrixPriceFor($deliveryCosts, 10.0))->toBe(5.5);
});

it('prices an existing matrix the same as before', function (float $orderAmount, string $deliveryType) {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['price' => 12.5, 'conditions' => [['type' => 'country_part_of', 'value' => 'EU']]],
        ['price' => 3.5, 'conditions' => [['type' => 'country', 'value' => 'NL'], ['type' => 'maximum_weight', 'value' => 500]]],
    ]);

    expect(matrixPriceFor($deliveryCosts, $orderAmount, $deliveryType))->toBe(6.95)
        ->and(matrixPriceFor($deliveryCosts, $orderAmount, $deliveryType, 'BE'))->toBe(12.5);
})->with([
    [0.0, 'standard'],
    [250.0, 'pickup'],
]);

it('lets the highest met minimum win between tiered thresholds, whatever their order', function () {
    // 3.00 from 50 and free from 100: at 120 both match, the 100 tier is the one the merchant meant
    $tiers = [
        ['name' => 'From 50', 'price' => 3.0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 50]]],
        ['name' => 'Free from 100', 'price' => 0.0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 100]]],
    ];

    expect(matrixPriceFor(deliveryCostsWithMatrix($tiers), 120.0))->toBe(0.0)
        ->and(matrixPriceFor(deliveryCostsWithMatrix(array_reverse($tiers)), 120.0))->toBe(0.0)
        ->and(matrixPriceFor(deliveryCostsWithMatrix($tiers), 75.0))->toBe(3.0);
});

it('lets the lowest met maximum win between tiered thresholds, whatever their order', function () {
    $tiers = [
        ['name' => 'Below 20', 'price' => 7.95, 'conditions' => [['type' => 'maximum_order_amount', 'value' => 20]]],
        ['name' => 'Below 100', 'price' => 5.95, 'conditions' => [['type' => 'maximum_order_amount', 'value' => 100]]],
    ];

    expect(matrixPriceFor(deliveryCostsWithMatrix($tiers), 10.0))->toBe(7.95)
        ->and(matrixPriceFor(deliveryCostsWithMatrix(array_reverse($tiers)), 10.0))->toBe(7.95)
        ->and(matrixPriceFor(deliveryCostsWithMatrix($tiers), 50.0))->toBe(5.95);
});

it('requires every order amount threshold of a rule', function () {
    // at 90 the first rule misses its minimum of 100, so the minimum of 80 prices it
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'From 50 or 100', 'price' => 3.0, 'conditions' => [
            ['type' => 'minimum_order_amount', 'value' => 50],
            ['type' => 'minimum_order_amount', 'value' => 100],
        ]],
        ['name' => 'From 80', 'price' => 1.0, 'conditions' => [['type' => 'minimum_order_amount', 'value' => 80]]],
    ]);

    expect(matrixPriceFor($deliveryCosts, 90.0))->toBe(1.0)
        ->and(matrixPriceFor($deliveryCosts, 120.0))->toBe(3.0);
});

it('never lets an unfinished minimum order amount match', function ($value) {
    // the matrix UI stores the value only once typed, but an empty or non-numeric value can still
    // arrive through the JSON textarea or an old save; (float) would turn it into 0 and match everything.
    // The country condition makes the unfinished rule outscore Base, so only the guard keeps Base.
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'Base', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'Unfinished minimum', 'price' => 99.0, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'minimum_order_amount', 'value' => $value],
        ]],
    ]);

    expect(matrixPriceFor($deliveryCosts, 10.0))->toBe(6.95)
        ->and(matrixPriceFor($deliveryCosts, 500.0))->toBe(6.95);
})->with(['an empty value' => [''], 'a non-numeric value' => ['abc']]);

it('lets an explicit zero minimum match, as a merchant typed it on purpose', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'Base', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'From zero', 'price' => 1.0, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'minimum_order_amount', 'value' => 0],
        ]],
    ]);

    expect(matrixPriceFor($deliveryCosts, 10.0))->toBe(1.0);
});

it('prices a delivery type the matrix does not offer as standard, the price the checkout shows', function () {
    // other delivery types take the standard price, see DeliveryCosts::calculate()
    $deliveryCosts = deliveryCostsWithMatrix(merchantConditionsMatrix());

    expect(matrixPriceFor($deliveryCosts, 120.0, 'same_day'))->toBe(1.0)
        ->and(matrixPriceFor($deliveryCosts, 10.0, 'same_day'))->toBe(6.95)
        ->and(matrixPriceFor($deliveryCosts, 120.0, 'express'))->toBe(1.0);
});

it('rules out a rule with any unmet condition, so a rule without conditions is the default', function () {
    // NL + max 2000 g scores more than the rule without conditions, but a 3 kg parcel misses its weight
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'NL light', 'price' => 5.0, 'conditions' => [
            ['type' => 'country', 'value' => 'NL'],
            ['type' => 'maximum_weight', 'value' => 2000],
        ]],
        ['name' => 'Default', 'price' => 10.0, 'conditions' => []],
    ]);

    $priceForWeight = static function (int $weight) use ($deliveryCosts): float {
        return invokePrivateMethod($deliveryCosts, 'calculate', [[
            'carrier_name'  => 'postnl',
            'package_type'  => 1,
            'country'       => 'NL',
            'weight'        => $weight,
            'order_amount'  => 30.0,
            'delivery_type' => 'standard',
        ]]);
    };

    expect($priceForWeight(1000))->toBe(5.0)
        ->and($priceForWeight(3000))->toBe(10.0);
});

it('falls back to the carrier shipping cost when no rule is fully met', function () {
    $deliveryCosts = deliveryCostsWithMatrix([
        ['name' => 'BE only', 'price' => 9.95, 'conditions' => [['type' => 'country', 'value' => 'BE']]],
    ], 4.5);

    expect(matrixPriceFor($deliveryCosts, 30.0))->toBe(4.5);
});
