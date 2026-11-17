<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Service\Config;

/** @param array<string, array<string, mixed>> $carrierSettings by carrier, as getDeliveryData() returns them */
function preselectPickup(array $carrierSettings, bool $pickupDefault = true, bool $compactView = false, ?string $savedOptions = null): bool
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('isPickupDefault')->andReturn($pickupDefault);
    $config->shouldReceive('getBoolConfig')->with(Config::XML_PATH_GENERAL, 'shipping_methods/compact_view')->andReturn($compactView);
    $config->shouldReceive('getDefaultCarrierName')->andReturn('postnl');

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getData')->with(Config::FIELD_DELIVERY_OPTIONS)->andReturn($savedOptions);
    $quote->shouldReceive('getShippingAddress')->andReturnNull();
    $quote->shouldReceive('getStoreId')->andReturn(1);

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', $quote);

    return invokePrivateMethod($checkout, 'shouldPreselectPickup', [$carrierSettings]);
}

it('preselects pickup when the default carrier offers pickup and the customer did not choose yet', function () {
    expect(preselectPickup(['postnl' => ['allowPickupLocations' => true]]))->toBeTrue();
});

it('does not preselect pickup when the setting is off', function () {
    expect(preselectPickup(['postnl' => ['allowPickupLocations' => true]], false))->toBeFalse();
});

it('does not preselect pickup when the default carrier cannot offer pickup', function () {
    expect(preselectPickup(['postnl' => ['allowPickupLocations' => false]]))->toBeFalse()
        ->and(preselectPickup(['dpd' => ['allowPickupLocations' => true]]))->toBeFalse();
});

it('does not preselect pickup in compact view', function () {
    expect(preselectPickup(['postnl' => ['allowPickupLocations' => true]], true, true))->toBeFalse();
});

it('does not preselect pickup when the quote already has a choice', function () {
    expect(preselectPickup(['postnl' => ['allowPickupLocations' => true]], true, false, '{"carrier":"postnl"}'))->toBeFalse();
});
