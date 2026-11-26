<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

it('reads a stored insurance as a whole number', function () {
    expect(ShipmentOptions::of([ShipmentOption::INSURANCE => '500'])->getInsurance())->toBe(500);
});

it('keeps an absent insurance and label description as null', function () {
    $options = ShipmentOptions::of([]);

    expect($options->getInsurance())->toBeNull()
        ->and($options->getLabelDescription())->toBeNull();
});

it('gives a numeric label description back as a string, not a type error', function () {
    // Stored JSON can hold a label description such as 12345 as a number.
    expect(ShipmentOptions::of([ShipmentOptions::LABEL_DESCRIPTION => 12345])->getLabelDescription())->toBe('12345');
});
