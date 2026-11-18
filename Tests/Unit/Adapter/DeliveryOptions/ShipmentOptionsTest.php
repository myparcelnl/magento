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
