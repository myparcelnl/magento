<?php

declare(strict_types=1);

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address;
use MyParcelNL\Magento\Helper\ShipmentOptions;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

function createShipmentOptions(
    string $countryId,
    string $carrier,
    array $options,
    bool $defaultOptionSet = false,
    ?bool $chosenOption = null
): ShipmentOptions {
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);
    // Empty item list: the product-attribute branch resolves to false without touching the DB.
    $order->shouldReceive('getItems')->andReturn([]);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn(Mockery::mock(Config::class));

    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasOptionSet')->andReturn($defaultOptionSet)->byDefault();
    $defaultOptions->shouldReceive('getChosenShipmentOption')->andReturn($chosenOption)->byDefault();

    return new ShipmentOptions($defaultOptions, $order, $objectManager, $carrier, $options);
}

it('returns true when the customer explicitly chose priority delivery', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOptions::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});

it('returns false when the customer explicitly declined priority delivery', function () {
    // Customer choice wins: the default setting is forced to true here, so the only
    // way this returns false is if the explicit false short-circuits the fallback
    // chain instead of falling through to the product attribute or defaults.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOptions::PRIORITY_DELIVERY => false,
    ], true);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('returns false for non-NL destinations even when explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('BE', CarrierPostNL::NAME, [
        ShipmentOptions::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('returns false for non-PostNL carriers even when explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('NL', 'dhlforyou', [
        ShipmentOptions::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('returns false without explicit choice, without products and without default', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, []);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('falls back to the default setting when there is no explicit choice and the cart yields no product priority', function () {
    // Empty items make the product leg yield null (it can only force true), so the
    // default setting is consulted.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [], true, null);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});

it('honours an explicit declination saved with the order over the default setting', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [], true, false);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('uses the choice saved with the order when the live options carry none', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [], false, true);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});
