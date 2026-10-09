<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * An option the carrier does not offer for the shipment is left off, as the PDK does: the API
 * refuses the shipment otherwise.
 */
it('leaves off an option the carrier does not offer, whatever switched it on, and says so', function () {
    $notices = captureNotices();

    $resolved = dependencyResolver(capabilityOptions(), [ShipmentOption::AGE_CHECK => OptionSource::PRODUCT])
        ->resolve()->toArray();

    expect($resolved[ShipmentOption::AGE_CHECK])->toBeFalse()
        ->and(implode("\n", $notices->getArrayCopy()))->toContain('age_check');
});

it('keeps an option the carrier offers', function () {
    $resolved = dependencyResolver(capabilityOptions(), [ShipmentOption::SIGNATURE => OptionSource::CHECKOUT])->resolve()->toArray();

    expect($resolved[ShipmentOption::SIGNATURE])->toBeTrue();
});

it('leaves everything as chosen while the capabilities are unknown', function () {
    $resolved = dependencyResolver(null, [ShipmentOption::AGE_CHECK => OptionSource::PRODUCT])->resolve()->toArray();

    expect($resolved[ShipmentOption::AGE_CHECK])->toBeTrue();
});
