<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;

/** @param array<string, string> $configValues by full path */
function methodAmountFor(string $carrier, array $configValues): float
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function (string $path) use ($configValues) {
        return $configValues[$path] ?? null;
    });

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')->andReturn(5.0);

    $method = newInstanceWithoutConstructor(Carrier::class);

    // Undeclared on Carrier, so set them the way its constructor does.
    (function () use ($config, $deliveryCosts): void {
        $this->config        = $config;
        $this->deliveryCosts = $deliveryCosts;
    })->call($method);

    setPrivateProperty($method, 'deliveryOptions', DeliveryOptions::fromCheckoutData([
        'carrier'         => $carrier,
        'packageType'     => PackageType::PACKAGE_NAME,
        'deliveryType'    => DeliveryType::EVENING_NAME,
        'shipmentOptions' => ['signature' => true],
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
