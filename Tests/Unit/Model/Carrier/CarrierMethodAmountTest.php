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
    float  $basePrice = 5.0,
    ?DeliveryCosts $deliveryCosts = null
): float {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function (string $path) use ($configValues) {
        return $configValues[$path] ?? null;
    });

    if (null === $deliveryCosts) {
        $deliveryCosts = Mockery::mock(DeliveryCosts::class);
        $deliveryCosts->shouldReceive('getBasePrice')->andReturn($basePrice);
    }

    $method = newInstanceWithoutConstructor(Carrier::class);
    setPrivateProperty($method, 'config', $config);
    setPrivateProperty($method, 'deliveryCosts', $deliveryCosts);

    setPrivateProperty($method, 'deliveryOptions', DeliveryOptions::fromCheckoutData([
        'carrier'         => $carrier,
        'packageType'     => PackageType::PACKAGE_NAME,
        'deliveryType'    => $deliveryType,
        'shipmentOptions' => $shipmentOptions,
    ]));

    return invokePrivateMethod($method, 'getMethodAmount', [Mockery::mock(Quote::class)]);
}

it('adds the fees of the delivery type and the options, read under the derived settings path of the carrier', function (string $carrier) {
    $path = Config::carrierPath($carrier);

    expect(methodAmountFor($carrier, [
        $path . 'evening/fee'            => '2.5',
        $path . 'delivery/signature_fee' => '1',
    ]))->toBe(8.5);
})->with(['postnl', 'bpost']);

it('prices the delivery type chosen in the checkout', function () {
    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')
        ->with(Mockery::type(Quote::class), null, null, null, DeliveryType::MORNING_NAME)
        ->once()
        ->andReturn(0.0);

    expect(methodAmountFor('postnl', [], DeliveryType::MORNING_NAME, [], 0.0, $deliveryCosts))->toBe(0.0);
});

it('floors the delivery type at 0 before the option fees, as the checkout shows it', function () {
    $path = Config::carrierPath('postnl');

    // evening base 3 with an evening fee of -5 is 0 in the checkout, plus the signature fee of 1
    expect(methodAmountFor('postnl', [
        $path . 'evening/fee'            => '-5',
        $path . 'delivery/signature_fee' => '1',
    ], DeliveryType::EVENING_NAME, ['signature' => true], 3.0))->toBe(1.0);
});

it('lists every option that is on in the method title, also one stored as 1', function () {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getGeneralConfig')->with('shipping_methods/show_details_in_summary')->andReturn('1');

    $method = newInstanceWithoutConstructor(Carrier::class);
    setPrivateProperty($method, 'config', $config);
    setPrivateProperty($method, 'deliveryOptions', DeliveryOptions::fromCheckoutData([
        'carrier'         => 'postnl',
        'packageType'     => PackageType::PACKAGE_NAME,
        'deliveryType'    => DeliveryType::STANDARD_NAME,
        'shipmentOptions' => ['signature' => 1, 'only_recipient' => 0, 'insurance' => 500],
    ]));

    $title = invokePrivateMethod($method, 'getMethodTitle', [Mockery::mock(Quote::class)]);

    expect($title)->toBe('PostNL Standard delivery, Package, Signature on receipt');
});
