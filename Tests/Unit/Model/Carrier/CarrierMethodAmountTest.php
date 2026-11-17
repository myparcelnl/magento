<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/** @param array<string, string> $configValues by full path */
function carrierPricingConfig(array $configValues = [], bool $pickupDefault = false, bool $pickupActive = true): Config
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')->andReturnUsing(static function (string $path) use ($configValues) {
        return $configValues[$path] ?? null;
    });
    $config->shouldReceive('isPickupDefault')->andReturn($pickupDefault);
    $config->shouldReceive('getDefaultCarrierName')->andReturn(CarrierPostNL::NAME);
    $config->shouldReceive('getCarrierConfig')->with(CarrierPostNL::NAME, 'pickup/active', 1)->andReturn($pickupActive ? '1' : '0');

    return $config;
}

function carrierForPricing(Config $config, DeliveryCosts $deliveryCosts): Carrier
{
    $method = newInstanceWithoutConstructor(Carrier::class);
    setPrivateProperty($method, 'config', $config);
    setPrivateProperty($method, 'deliveryCosts', $deliveryCosts);

    return $method;
}

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
    if (null === $deliveryCosts) {
        $deliveryCosts = Mockery::mock(DeliveryCosts::class);
        $deliveryCosts->shouldReceive('getBasePrice')->andReturn($basePrice);
    }

    $method = carrierForPricing(carrierPricingConfig($configValues), $deliveryCosts);

    setPrivateProperty($method, 'deliveryOptions', new DeliveryOptionsV3Adapter([
        'carrier'         => $carrier,
        'packageType'     => AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME,
        'deliveryType'    => $deliveryType,
        'shipmentOptions' => $shipmentOptions,
    ]));

    return invokePrivateMethod($method, 'getMethodAmount', [Mockery::mock(Quote::class)]);
}

it('adds the fee of the delivery type and of the options to the base price', function () {
    $path = Config::CARRIERS_XML_PATH_MAP[CarrierPostNL::NAME];

    expect(methodAmountFor(CarrierPostNL::NAME, [
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

    expect(methodAmountFor(CarrierPostNL::NAME, [], AbstractConsignment::DELIVERY_TYPE_MORNING_NAME, [], 0.0, $deliveryCosts))->toBe(0.0);
});

it('floors the delivery type at 0 before the option fees, as the checkout shows it', function () {
    $path = Config::CARRIERS_XML_PATH_MAP[CarrierPostNL::NAME];

    // evening base 3 with an evening fee of -5 is 0 in the checkout, plus the signature fee of 1
    expect(methodAmountFor(CarrierPostNL::NAME, [
        $path . 'evening/fee'            => '-5',
        $path . 'delivery/signature_fee' => '1',
    ], AbstractConsignment::DELIVERY_TYPE_EVENING_NAME, ['signature' => true], 3.0))->toBe(1.0);
});

/**
 * Prices a quote without delivery options.
 *
 * @param array<string, string> $configValues by full path
 * @return array{deliveryType: ?string, amount: float}
 */
function priceBeforeChoice(bool $pickupDefault, array $configValues = [], bool $pickupActive = true): array
{
    $pricedDeliveryType = null;
    $deliveryCosts      = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePrice')
        ->andReturnUsing(static function ($quote, $carrier, $packageType, $country, $deliveryType) use (&$pricedDeliveryType) {
            $pricedDeliveryType = $deliveryType;

            return 5.0;
        });

    $quote = quoteWithChoice();

    $method = carrierForPricing(carrierPricingConfig($configValues, $pickupDefault, $pickupActive), $deliveryCosts);
    $amount = invokePrivateMethod($method, 'getMethodAmount', [$quote]);

    return ['deliveryType' => $pricedDeliveryType, 'amount' => $amount];
}

it('prices pickup before the customer chooses when pickup is the default', function () {
    expect(priceBeforeChoice(true)['deliveryType'])->toBe(AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME);
});

it('adds the pickup fee of the default carrier before the customer chooses', function () {
    $path = Config::CARRIERS_XML_PATH_MAP[CarrierPostNL::NAME];

    expect(priceBeforeChoice(true, [$path . 'pickup/fee' => '-4.5'])['amount'])->toBe(0.5);
});

it('prices standard delivery before the customer chooses by default', function () {
    expect(priceBeforeChoice(false)['deliveryType'])->toBe(AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME);
});

it('prices standard delivery before the customer chooses when the default carrier has no pickup', function () {
    expect(priceBeforeChoice(true, [], false)['deliveryType'])->toBe(AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME);
});

it('titles the rate as pickup of the default carrier before the customer chooses', function () {
    $config = carrierPricingConfig([], true);
    $config->shouldReceive('getGeneralConfig')->with('shipping_methods/show_details_in_summary')->andReturn('1');

    $quote = quoteWithChoice();

    $method = carrierForPricing($config, Mockery::mock(DeliveryCosts::class));

    expect(invokePrivateMethod($method, 'getMethodTitle', [$quote]))->toBe('PostNL Pickup locations, Package');
});
