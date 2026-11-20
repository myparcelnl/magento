<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;

/** @param array<string, array<string, mixed>> $carrierSettings by carrier, as getDeliveryData() returns them */
function preselectPickup(array $carrierSettings, bool $pickupDefault, bool $compactView, ?string $savedOptions, string $defaultCarrier): bool
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('isPickupDefault')->andReturn($pickupDefault);
    $config->shouldReceive('getBoolConfig')->with(Config::XML_PATH_GENERAL, 'shipping_methods/compact_view')->andReturn($compactView);
    $config->shouldReceive('getDefaultCarrierName')->andReturn($defaultCarrier);

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', quoteWithDeliveryOptions($savedOptions));

    return invokePrivateMethod($checkout, 'shouldPreselectPickup', [$carrierSettings]);
}

it('decides whether the widget opens on pickup', function (array $settings, bool $pickupDefault, bool $compactView, ?string $saved, string $default, bool $expected) {
    expect(preselectPickup($settings, $pickupDefault, $compactView, $saved, $default))->toBe($expected);
})->with([
    'the default carrier offers pickup, no choice yet' => [['postnl' => ['allowPickupLocations' => true]], true, false, null, 'postnl', true],
    'the default carrier is not PostNL'                => [['postnl' => ['allowPickupLocations' => false], 'dpd' => ['allowPickupLocations' => true]], true, false, null, 'dpd', true],
    'the setting is off'                               => [['postnl' => ['allowPickupLocations' => true]], false, false, null, 'postnl', false],
    'the default carrier cannot offer pickup'          => [['postnl' => ['allowPickupLocations' => false]], true, false, null, 'postnl', false],
    'only another carrier offers pickup'               => [['dpd' => ['allowPickupLocations' => true]], true, false, null, 'postnl', false],
    'compact view'                                     => [['postnl' => ['allowPickupLocations' => true]], true, true, null, 'postnl', false],
    'the quote already has a choice'                   => [['postnl' => ['allowPickupLocations' => true]], true, false, '{"carrier":"postnl"}', 'postnl', false],
]);

it('sends the preselect in the checkout response, from the carrier settings the checkout builds', function () {
    $config = checkoutPriceConfig(true);
    $config->shouldReceive('isPickupDefault')->andReturn(true);
    $config->shouldReceive('getGeneralConfig')->andReturn('');
    $config->shouldReceive('getIntegerConfig')->andReturn(0);
    $config->shouldReceive('getVersion')->andReturn('0.0.0');

    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn('NL');

    $quote = quoteWithDeliveryOptions();
    $quote->shouldReceive('getAllItems')->andReturn([]);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getOrderAmount')->andReturn(0.0);
    $deliveryCosts->shouldReceive('getBasePriceForClient')->andReturn(0.0);

    $store = Mockery::mock(Store::class);
    $store->shouldReceive('getCurrentCurrency->getCode')->andReturn('EUR');
    $store->shouldReceive('getBaseUrl')->andReturn('');
    $storeManager = Mockery::mock(StoreManagerInterface::class);
    $storeManager->shouldReceive('getStore')->andReturn($store);

    $checkout = checkoutForPricing($config, passThroughTax(), $deliveryCosts, $quote);
    setPrivateProperty($checkout, 'storeManager', $storeManager);

    expect($checkout->getDeliveryOptions()['root']['data']['preselectPickup'])->toBeTrue();
});
