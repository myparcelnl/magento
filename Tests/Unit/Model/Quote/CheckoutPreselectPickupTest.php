<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

/** @param array<string, array<string, mixed>> $carrierSettings by carrier, as getDeliveryData() returns them */
function preselectPickup(
    array   $carrierSettings,
    bool    $pickupDefault = true,
    bool    $compactView = false,
    ?string $savedOptions = null,
    string  $defaultCarrier = CarrierPostNL::NAME
): bool {
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('isPickupDefault')->andReturn($pickupDefault);
    $config->shouldReceive('getBoolConfig')->with(Config::XML_PATH_GENERAL, 'shipping_methods/compact_view')->andReturn($compactView);
    $config->shouldReceive('getDefaultCarrierName')->andReturn($defaultCarrier);

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', quoteWithChoice($savedOptions));

    return invokePrivateMethod($checkout, 'shouldPreselectPickup', [$carrierSettings]);
}

it('preselects pickup when the default carrier offers pickup and the customer did not choose yet', function () {
    expect(preselectPickup([CarrierPostNL::NAME => ['allowPickupLocations' => true]]))->toBeTrue();
});

it('looks up pickup for the default carrier, not for PostNL', function () {
    $settings = [CarrierPostNL::NAME => ['allowPickupLocations' => false], CarrierDPD::NAME => ['allowPickupLocations' => true]];

    expect(preselectPickup($settings, true, false, null, CarrierDPD::NAME))->toBeTrue();
});

it('does not preselect pickup when the setting is off', function () {
    expect(preselectPickup([CarrierPostNL::NAME => ['allowPickupLocations' => true]], false))->toBeFalse();
});

it('does not preselect pickup when the default carrier cannot offer pickup', function () {
    expect(preselectPickup([CarrierPostNL::NAME => ['allowPickupLocations' => false]]))->toBeFalse()
        ->and(preselectPickup([CarrierDPD::NAME => ['allowPickupLocations' => true]]))->toBeFalse();
});

it('does not preselect pickup in compact view', function () {
    expect(preselectPickup([CarrierPostNL::NAME => ['allowPickupLocations' => true]], true, true))->toBeFalse();
});

it('does not preselect pickup when the quote already has a choice', function () {
    expect(preselectPickup([CarrierPostNL::NAME => ['allowPickupLocations' => true]], true, false, '{"carrier":"postnl"}'))->toBeFalse();
});

it('sends the preselect in the checkout response, from the carrier settings the checkout builds', function () {
    $config = checkoutPriceConfig(true);
    $config->shouldReceive('isPickupDefault')->andReturn(true);
    $config->shouldReceive('getGeneralConfig')->andReturn('');
    $config->shouldReceive('getIntegerConfig')->andReturn(0);
    $config->shouldReceive('getVersion')->andReturn('0.0.0');

    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn('NL');

    $quote = quoteWithChoice();
    $quote->shouldReceive('getAllItems')->andReturn([]);
    $quote->shouldReceive('getShippingAddress')->andReturn($address);

    $tax = Mockery::mock(Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturnUsing(static fn ($price) => $price);

    $deliveryCosts = Mockery::mock(DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getOrderAmount')->andReturn(0.0);
    $deliveryCosts->shouldReceive('getBasePriceForClient')->andReturn(0.0);

    $store = Mockery::mock(Store::class);
    $store->shouldReceive('getCurrentCurrency->getCode')->andReturn('EUR');
    $store->shouldReceive('getBaseUrl')->andReturn('');
    $storeManager = Mockery::mock(StoreManagerInterface::class);
    $storeManager->shouldReceive('getStore')->andReturn($store);

    $checkout = checkoutForPricing($config, $tax, $deliveryCosts, $quote);
    setPrivateProperty($checkout, 'storeManager', $storeManager);

    expect($checkout->getDeliveryOptions()['root']['data']['preselectPickup'])->toBeTrue();
});
