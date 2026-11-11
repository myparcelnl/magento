<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;

/**
 * @param array<string, string> $configValues by full path
 * @param array<string, bool>   $shipmentOptions
 */
function methodAmountFor(
    string $carrier,
    array  $configValues,
    string $deliveryType = DeliveryType::EVENING_NAME,
    array  $shipmentOptions = ['signature' => true],
    float  $basePrice = 5.0
): float {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function (string $path) use ($configValues) {
        return $configValues[$path] ?? null;
    });

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')->andReturn($basePrice);

    $method = newInstanceWithoutConstructor(Carrier::class);

    // Undeclared on Carrier, so set them the way its constructor does.
    (function () use ($config, $deliveryCosts): void {
        $this->config        = $config;
        $this->deliveryCosts = $deliveryCosts;
    })->call($method);

    setPrivateProperty($method, 'deliveryOptions', DeliveryOptions::fromCheckoutData([
        'carrier'         => $carrier,
        'packageType'     => PackageType::PACKAGE_NAME,
        'deliveryType'    => $deliveryType,
        'shipmentOptions' => $shipmentOptions,
    ]));

    return invokePrivateMethod($method, 'getMethodAmount', [Mockery::mock(Quote::class)]);
}

it('reads the fees of a carrier under its derived settings path', function (string $carrier) {
    $path = Config::carrierPath($carrier);

    expect(methodAmountFor($carrier, [
        $path . 'evening/fee'             => '2.5',
        $path . 'delivery/signature_fee'  => '1',
    ]))->toBe(8.5);
})->with(['postnl', 'bpost']);

it('prices the delivery type chosen in the checkout', function () {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturn(null);

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')
        ->with(Mockery::type(Quote::class), null, null, null, DeliveryType::MORNING_NAME)
        ->once()
        ->andReturn(0.0);

    $method = newInstanceWithoutConstructor(Carrier::class);

    (function () use ($config, $deliveryCosts): void {
        $this->config        = $config;
        $this->deliveryCosts = $deliveryCosts;
    })->call($method);

    setPrivateProperty($method, 'deliveryOptions', DeliveryOptions::fromCheckoutData([
        'carrier'      => 'postnl',
        'packageType'  => PackageType::PACKAGE_NAME,
        'deliveryType' => DeliveryType::MORNING_NAME,
    ]));

    expect(invokePrivateMethod($method, 'getMethodAmount', [Mockery::mock(Quote::class)]))->toBe(0.0);
});

it('charges the same day fee for same day as an option on standard delivery and as a delivery type', function (string $deliveryType, array $shipmentOptions) {
    $path = Config::carrierPath('dhlforyou');

    expect(methodAmountFor('dhlforyou', [$path . 'delivery/same_day_delivery_fee' => '3'], $deliveryType, $shipmentOptions))->toBe(8.0);
})->with([
    'an option'       => [DeliveryType::STANDARD_NAME, ['same_day_delivery' => true]],
    'a delivery type' => [DeliveryType::SAME_DAY_NAME, []],
    'both'            => [DeliveryType::SAME_DAY_NAME, ['same_day_delivery' => true]],
]);

it('floors the delivery type at 0 before the option fees, as the checkout shows it', function () {
    $path = Config::carrierPath('postnl');

    // evening base 3 with an evening fee of -5 is 0 in the checkout, plus the signature fee of 1
    expect(methodAmountFor('postnl', [
        $path . 'evening/fee'            => '-5',
        $path . 'delivery/signature_fee' => '1',
    ], DeliveryType::EVENING_NAME, ['signature' => true], 3.0))->toBe(1.0);
});
