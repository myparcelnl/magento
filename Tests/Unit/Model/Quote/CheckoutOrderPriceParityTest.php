<?php

declare(strict_types=1);

use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

// End to end: the real DeliveryCosts, Checkout::getDeliveryData() and Carrier::getMethodAmount() on one matrix.

afterEach(function () {
    (new ReflectionProperty(ObjectManager::class, '_instance'))->setValue(null, null);
});

/** NL 6.95, free pickup, standard 1.00 from 50. */
function parityConfig(bool $totalView, string $carrier = 'postnl'): Config
{
    $matrix = [
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

    $config = checkoutPriceConfig($totalView, [], [], $carrier);
    $config->shouldReceive('getGeneralConfig')->with('matrix/delivery_costs')->andReturn(json_encode($matrix));
    $config->shouldReceive('getGeneralConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getMagentoCarrierConfig')->with('shipping_cost')->andReturn('9.99');

    return $config;
}

function parityTax(): Tax
{
    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price);
    $tax->shouldReceive('discountIncludesTax')->andReturn(false);
    $tax->shouldReceive('appliesTaxAfterDiscount')->andReturn(true);

    return $tax;
}

function parityDeliveryCosts(Config $config): DeliveryCosts
{
    $weight = Mockery::mock(Weight::class);
    $weight->shouldReceive('getEmptyPackageWeightInGrams')->andReturn(0);
    $weight->shouldReceive('getQuoteWeightInGrams')->andReturn(1000);

    return new DeliveryCosts($weight, $config, parityTax());
}

/** One visible item sets the order amount. */
function parityQuote(float $orderAmount): Quote
{
    $item = Mockery::mock(Item::class);
    $item->shouldReceive('getRowTotal')->andReturn($orderAmount / 1.21);
    $item->shouldReceive('getRowTotalInclTax')->andReturn($orderAmount);
    $item->shouldReceive('getDiscountAmount')->andReturn(0.0);

    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn('NL');

    $quote = Mockery::mock(Quote::class)->shouldIgnoreMissing();
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getAllVisibleItems')->andReturn([$item]);
    $quote->shouldReceive('getStoreId')->andReturn(1);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    return $quote;
}

function parityObjectManager(Config $config): void
{
    $om = Mockery::mock(ObjectManagerInterface::class);
    $om->shouldReceive('get')->with(Config::class)->andReturn($config);
    ObjectManager::setInstance($om);
}

/** What the order charges for the chosen delivery type, through the real Carrier. */
function parityOrderAmount(Config $config, Quote $quote, string $deliveryType, string $carrier = 'postnl', array $shipmentOptions = []): float
{
    $method        = newInstanceWithoutConstructor(Carrier::class);
    $deliveryCosts = parityDeliveryCosts($config);
    setPrivateProperty($method, 'config', $config);
    setPrivateProperty($method, 'deliveryCosts', $deliveryCosts);

    $checkoutData = [
        'carrier'         => $carrier,
        'packageType'     => AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME,
        'deliveryType'    => $deliveryType,
        'shipmentOptions' => $shipmentOptions,
    ];
    if (AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME === $deliveryType) {
        $checkoutData['pickupLocation'] = [
            'location_name' => 'Shop', 'location_code' => 'X1', 'street' => 'Main',
            'number' => '1', 'postal_code' => '1000AA', 'city' => 'Amsterdam', 'cc' => 'NL',
        ];
    }
    setPrivateProperty($method, 'deliveryOptions', new DeliveryOptionsV3Adapter($checkoutData));

    return invokePrivateMethod($method, 'getMethodAmount', [$quote]);
}

it('the checkout shows per delivery type the price the order charges', function (float $orderAmount) {
    $config = parityConfig(true);
    parityObjectManager($config);

    $widget = checkoutCarrierSettings($config, parityTax(), parityDeliveryCosts($config), parityQuote($orderAmount));

    foreach ([
        'standard' => $widget['priceStandardDelivery'],
        'pickup'   => $widget['pricePickup'],
        'morning'  => $widget['priceMorningDelivery'],
        'evening'  => $widget['priceEveningDelivery'],
    ] as $deliveryType => $widgetPrice) {
        $orderPrice = parityOrderAmount($config, parityQuote($orderAmount), $deliveryType);
        expect($orderPrice)->toBe($widgetPrice, "order and widget disagree on $deliveryType at $orderAmount");
    }
})->with(['below the threshold' => [30.0], 'above the threshold' => [120.0]]);

it('surcharge view shows the difference with standard delivery', function () {
    $config = parityConfig(false);
    parityObjectManager($config);

    $widget = checkoutCarrierSettings($config, parityTax(), parityDeliveryCosts($config), parityQuote(120.0));

    expect($widget['priceStandardDelivery'])->toBe(0.0)
        ->and($widget['pricePickup'])->toBe(-1.0)          // free pickup against the 1.00 standard tier
        ->and($widget['priceMorningDelivery'])->toBe(5.95); // the 1.00 tier names standard only, so morning takes the 6.95 base
});
