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
    bool $defaultOptionSet = false
): ShipmentOptions {
    $address = Mockery::mock(Address::class);
    $address->shouldReceive('getCountryId')->andReturn($countryId);

    $order = Mockery::mock(Order::class);
    $order->shouldReceive('getShippingAddress')->andReturn($address);

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('get')->with(Config::class)->andReturn(Mockery::mock(Config::class));

    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasOptionSet')->andReturn($defaultOptionSet)->byDefault();

    return new ShipmentOptions($defaultOptions, $order, $objectManager, $carrier, $options);
}

it('returns true when priority delivery was explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOptions::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});

it('returns false when priority delivery was explicitly declined', function () {
    // The fallback is forced to true here, so this only returns false when the
    // explicit false in the live options short-circuits the fallback.
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

it('returns false without explicit choice and without saved choice or default', function () {
    // Off unless explicitly enabled: no choice anywhere means no priority delivery.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, []);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('falls back to the saved checkout choice or default when the live options carry none', function () {
    // hasOptionSet() covers both the choice saved with the order and the
    // configured default option.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [], true);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});
