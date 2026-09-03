<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;

// createShipmentOptions() lives in Tests/Helpers/ShipmentOptionsResolverFixtures.php.

it('returns true when priority delivery was explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOption::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeTrue();
});

it('returns false when priority delivery was explicitly declined', function () {
    // The fallback is forced to true here, so this only returns false when the
    // explicit false in the live options short-circuits the fallback.
    $shipmentOptions = createShipmentOptions('NL', CarrierPostNL::NAME, [
        ShipmentOption::PRIORITY_DELIVERY => false,
    ], true);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('returns false for non-NL destinations even when explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('BE', CarrierPostNL::NAME, [
        ShipmentOption::PRIORITY_DELIVERY => true,
    ]);

    expect($shipmentOptions->hasPriorityDelivery())->toBeFalse();
});

it('returns false for non-PostNL carriers even when explicitly chosen', function () {
    $shipmentOptions = createShipmentOptions('NL', 'dhlforyou', [
        ShipmentOption::PRIORITY_DELIVERY => true,
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
