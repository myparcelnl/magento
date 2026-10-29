<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\PackageTypeResolver;

/**
 * Pickup prices like morning and evening: through deliveryTypePrice(), so surcharge view shows the
 * difference with standard delivery (negative for a cheaper pickup), and its fee goes through
 * tax->shippingPrice() like every other fee.
 *
 * The matrix prices standard at 6.95 and pickup at 0; the tax mock doubles every fee it is handed,
 * so a fee that skips it stands out.
 */
function pickupPriceSettings(bool $totalView, float $pickupFee): array
{
    $packageTypes = Mockery::mock(PackageTypeResolver::class);
    $packageTypes->shouldReceive('resolve')->andReturn(PackageType::PACKAGE_NAME)->byDefault();
    $packageTypes->shouldReceive('maxMailboxWeight')->andReturn(2000.0)->byDefault();
    $packageTypes->shouldReceive('cartWeight')->andReturn(100.0)->byDefault();

    $cartRules = Mockery::mock(CartShippingRules::class);
    $cartRules->shouldReceive('forcedLimitingOptions')->andReturn([])->byDefault();
    $cartRules->shouldReceive('forcedCheckoutOptions')->andReturn([])->byDefault();
    $cartRules->shouldReceive('hidesDeliveryOptions')->andReturn(false)->byDefault();
    $cartRules->shouldReceive('allowsPriorityDelivery')->andReturn(false)->byDefault();
    $cartRules->shouldReceive('excludesParcelLockers')->andReturn(false)->byDefault();
    $cartRules->shouldReceive('forcesAgeCheck')->andReturn(false)->byDefault();
    $cartRules->shouldReceive('dropOffDelay')->andReturn(null)->byDefault();

    $config = createPermissiveConfig();
    if ($totalView) {
        $config->shouldReceive('getConfigValue')
            ->withArgs(static fn ($path) => false !== strpos((string) $path, 'delivery_options_prices'))
            ->andReturn(PriceDeliveryOptionsView::TOTAL);
    }
    $config->shouldReceive('getFloatConfig')
        ->withArgs(static fn ($path, $key) => 'pickup/fee' === $key)
        ->andReturn($pickupFee);

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getStoreId')->andReturn(1)->byDefault();

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

    $tax = Mockery::mock(MyParcelNL\Magento\Service\Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price * 2)->byDefault();
    setPrivateProperty($checkout, 'tax', $tax);

    $deliveryCosts = Mockery::mock(MyParcelNL\Magento\Service\DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePriceForClient')->andReturnUsing(
        static fn ($quote, $carrier, $packageType, $country, $deliveryType) => 'pickup' === $deliveryType ? 0.0 : 6.95
    )->byDefault();
    setPrivateProperty($checkout, 'deliveryCosts', $deliveryCosts);

    $data = invokePrivateMethod($checkout, 'getDeliveryData', [PackageType::PACKAGE_NAME, 'NL']);

    return $data['carrierSettings']['postnl'];
}

it('shows free pickup as the difference with standard delivery in surcharge view', function () {
    $settings = pickupPriceSettings(false, 0.0);

    expect($settings['pricePickup'])->toBe(-6.95)
        ->and($settings['priceStandardDelivery'])->toBe(0.0);
});

it('shows free pickup as its total in total view', function () {
    $settings = pickupPriceSettings(true, 0.0);

    expect($settings['pricePickup'])->toBe(0.0)
        ->and($settings['priceStandardDelivery'])->toBe(6.95);
});

it('passes the pickup fee through the tax settings like every other fee', function () {
    // the doubling tax mock turns the 2.00 fee into 4.00: pickup base 0 - standard 6.95 + 4.00
    expect(pickupPriceSettings(false, 2.0)['pricePickup'])->toBe(-2.95);
});
