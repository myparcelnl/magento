<?php

declare(strict_types=1);

/**
 * INT-2147's acceptance criterion, end to end: the checkout shows per delivery type the same price
 * the order charges. The real DeliveryCosts prices the story's matrix (NL 6.95, free pickup,
 * standard 1.00 from 50), the real Checkout::getDeliveryData() produces the widget prices, and the
 * real Carrier::getMethodAmount() charges the order.
 */

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Magento\Service\Weight;

function flowConfig(bool $totalView): Config
{
    $matrix = [
        ['name' => 'Base', 'price' => 6.95, 'conditions' => [['type' => 'country', 'value' => 'NL']]],
        ['name' => 'Free pickup', 'price' => 0, 'conditions' => [['type' => 'delivery_type', 'value' => 'pickup']]],
        ['name' => 'Home delivery from 50', 'price' => 1, 'conditions' => [
            ['type' => 'minimum_order_amount', 'value' => 50],
            ['type' => 'delivery_type', 'value' => 'standard'],
        ]],
    ];

    $config = createPermissiveConfig();
    $config->shouldReceive('getGeneralConfig')->with('matrix/delivery_costs')->andReturn(json_encode($matrix));
    $config->shouldReceive('getGeneralConfig')->with('matrix/use_free_shipping')->andReturnNull();
    $config->shouldReceive('getGeneralConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getMagentoCarrierConfig')->with('shipping_cost')->andReturn('9.99');
    $config->shouldReceive('getDefaultCarrierName')->andReturn('postnl');
    $config->shouldReceive('getFloatConfig')
        ->withArgs(static fn ($path, $key) => 'delivery/same_day_delivery_fee' === $key)
        ->andReturn(1.5);
    // the order side reads its fees as raw config values by full path
    $config->shouldReceive('getConfigValue')
        ->withArgs(static fn ($path) => false !== strpos((string) $path, 'delivery/same_day_delivery_fee'))
        ->andReturn('1.5');
    if ($totalView) {
        $config->shouldReceive('getConfigValue')
            ->withArgs(static fn ($path) => false !== strpos((string) $path, 'delivery_options_prices'))
            ->andReturn(PriceDeliveryOptionsView::TOTAL);
    }

    return $config;
}

function flowDeliveryCosts(Config $config): DeliveryCosts
{
    $weight = Mockery::mock(Weight::class);
    $weight->shouldReceive('getEmptyPackageWeightInGrams')->andReturn(0);
    $weight->shouldReceive('getQuoteWeightInGrams')->andReturn(1000);

    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price);
    $tax->shouldReceive('discountIncludesTax')->andReturn(false);
    $tax->shouldReceive('appliesTaxAfterDiscount')->andReturn(true);

    return new DeliveryCosts($weight, $config, $tax);
}

/** One visible item sets the order amount; the stored delivery options JSON is the order side's choice. */
function flowQuote(float $orderAmount, ?string $chosenDeliveryType = null): Quote
{
    $item = Mockery::mock(Item::class);
    $item->shouldReceive('getRowTotal')->andReturn($orderAmount / 1.21);
    $item->shouldReceive('getRowTotalInclTax')->andReturn($orderAmount);
    $item->shouldReceive('getDiscountAmount')->andReturn(0.0);

    $address = Mockery::mock(\Magento\Quote\Model\Quote\Address::class);
    $address->shouldReceive('getCountryId')->andReturn('NL');

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getAllVisibleItems')->andReturn([$item]);
    $quote->shouldReceive('getStoreId')->andReturn(1);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);
    $quote->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn(
        null === $chosenDeliveryType ? null : json_encode([
            'carrier'      => 'postnl',
            'packageType'  => PackageType::PACKAGE_NAME,
            'deliveryType' => $chosenDeliveryType,
        ])
    );

    return $quote;
}

function flowObjectManager(Config $config): void
{
    $om = Mockery::mock(\Magento\Framework\ObjectManagerInterface::class);
    $om->shouldReceive('get')->with(Config::class)->andReturn($config);
    \Magento\Framework\App\ObjectManager::setInstance($om);
}

/** The widget prices the checkout hands the delivery options widget. */
function flowWidgetPrices(Config $config, DeliveryCosts $deliveryCosts, Quote $quote): array
{
    $packageTypes = Mockery::mock(PackageTypeResolver::class);
    $packageTypes->shouldReceive('resolve')->andReturn(PackageType::PACKAGE_NAME)->byDefault();
    $packageTypes->shouldReceive('maxMailboxWeight')->andReturn(2000.0)->byDefault();
    $packageTypes->shouldReceive('cartWeight')->andReturn(100.0)->byDefault();

    $cartRules = Mockery::mock(CartShippingRules::class);
    foreach (['forcedLimitingOptions' => [], 'forcedCheckoutOptions' => [], 'hidesDeliveryOptions' => false, 'allowsPriorityDelivery' => false,
              'excludesParcelLockers' => false, 'forcesAgeCheck' => false, 'dropOffDelay' => null] as $fn => $answer) {
        $cartRules->shouldReceive($fn)->andReturn($answer)->byDefault();
    }

    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price)->byDefault();

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'packageTypes', $packageTypes);
    setPrivateProperty($checkout, 'cartRules', $cartRules);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', $quote);
    setPrivateProperty($checkout, 'storeId', 1);
    setPrivateProperty($checkout, 'capabilityLookup', capabilityLookupWith(CapabilitySet::permissive(), 'NL', 1));
    setPrivateProperty($checkout, 'storedAccount', storedAccountAt('NL'));
    setPrivateProperty($checkout, 'contractDefinitions', storedContractFor(1, ['POSTNL']));
    setPrivateProperty($checkout, 'dropOffSettings', new DropOffSettings($config));
    setPrivateProperty($checkout, 'tax', $tax);
    setPrivateProperty($checkout, 'deliveryCosts', $deliveryCosts);

    $data = invokePrivateMethod($checkout, 'getDeliveryData', [PackageType::PACKAGE_NAME, 'NL']);

    return $data['carrierSettings']['postnl'];
}

/** What the order charges for the chosen delivery type, through the real Carrier. */
function flowOrderAmount(Config $config, DeliveryCosts $deliveryCosts, Quote $quote, string $deliveryType): float
{
    $carrier = newInstanceWithoutConstructor(Carrier::class);
    (function () use ($config, $deliveryCosts): void {
        $this->config        = $config;
        $this->deliveryCosts = $deliveryCosts;
    })->call($carrier);

    $checkoutData = [
        'carrier'      => 'postnl',
        'packageType'  => PackageType::PACKAGE_NAME,
        'deliveryType' => $deliveryType,
    ];
    if ('pickup' === $deliveryType) {
        $checkoutData['isPickup']       = true;
        $checkoutData['pickupLocation'] = [
            'location_name' => 'Shop', 'location_code' => 'X1', 'street' => 'Main',
            'number' => '1', 'postal_code' => '1000AA', 'city' => 'Amsterdam', 'cc' => 'NL',
        ];
    }
    setPrivateProperty($carrier, 'deliveryOptions', \MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions::fromCheckoutData($checkoutData));

    return invokePrivateMethod($carrier, 'getMethodAmount', [$quote]);
}

it('flow: the checkout shows per delivery type the price the order charges', function (float $orderAmount) {
    $config = flowConfig(true);
    flowObjectManager($config);
    $deliveryCosts = flowDeliveryCosts($config);

    $widget = flowWidgetPrices($config, $deliveryCosts, flowQuote($orderAmount));

    foreach ([
        'standard' => $widget['priceStandardDelivery'],
        'pickup'   => $widget['pricePickup'],
        'same_day' => $widget['priceSameDayDelivery'],
        'morning'  => $widget['priceMorningDelivery'],
        'evening'  => $widget['priceEveningDelivery'],
    ] as $deliveryType => $widgetPrice) {
        $orderPrice = flowOrderAmount($config, flowDeliveryCosts($config), flowQuote($orderAmount, $deliveryType), $deliveryType);
        expect($orderPrice)->toBe($widgetPrice, "order and widget disagree on $deliveryType at $orderAmount");
    }
})->with(['below the threshold' => [30.0], 'above the threshold' => [120.0]]);

it('flow: surcharge view shows the difference with standard delivery', function () {
    $config = flowConfig(false);
    flowObjectManager($config);

    $widget = flowWidgetPrices($config, flowDeliveryCosts($config), flowQuote(120.0));

    expect($widget['priceStandardDelivery'])->toBe(0.0)
        ->and($widget['pricePickup'])->toBe(-1.0)       // free pickup against the 1.00 standard tier
        ->and($widget['priceMorningDelivery'])->toBe(5.95) // the 1.00 tier names standard only, so morning takes the 6.95 base
        ->and($widget['priceSameDayDelivery'])->toBe(1.5);
});
