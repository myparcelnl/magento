<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/**
 * @param array<string, string> $configValues by full path
 * @param array<string, bool>   $shipmentOptions
 */
function methodAmountFor(
    string $carrier,
    array  $configValues,
    string $deliveryType = AbstractConsignment::DELIVERY_TYPE_EVENING_NAME,
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

    setPrivateProperty($method, 'deliveryOptions', new DeliveryOptionsV3Adapter([
        'carrier'         => $carrier,
        'packageType'     => AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME,
        'deliveryType'    => $deliveryType,
        'shipmentOptions' => $shipmentOptions,
    ]));

    return invokePrivateMethod($method, 'getMethodAmount', [Mockery::mock(Quote::class)]);
}

it('adds the fee of the delivery type and of the options to the base price', function () {
    $path = Config::CARRIERS_XML_PATH_MAP['postnl'];

    expect(methodAmountFor('postnl', [
        $path . 'evening/fee'            => '2.5',
        $path . 'delivery/signature_fee' => '1',
    ]))->toBe(8.5);
});

it('prices the delivery type chosen in the checkout', function () {
    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')
        ->with(Mockery::type(Quote::class), null, null, null, AbstractConsignment::DELIVERY_TYPE_MORNING_NAME)
        ->once()
        ->andReturn(0.0);

    expect(methodAmountFor('postnl', [], AbstractConsignment::DELIVERY_TYPE_MORNING_NAME, [], 0.0, $deliveryCosts))->toBe(0.0);
});

it('floors the delivery type at 0 before the option fees, as the checkout shows it', function () {
    $path = Config::CARRIERS_XML_PATH_MAP['postnl'];

    // evening base 3 with an evening fee of -5 is 0 in the checkout, plus the signature fee of 1
    expect(methodAmountFor('postnl', [
        $path . 'evening/fee'            => '-5',
        $path . 'delivery/signature_fee' => '1',
    ], AbstractConsignment::DELIVERY_TYPE_EVENING_NAME, ['signature' => true], 3.0))->toBe(1.0);
});
