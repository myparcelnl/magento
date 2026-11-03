<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions as ResolvedOptions;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/** The delivery date goes with every package type, to every country. */

it('sends the delivery date with a package small', function () {
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE_SMALL);

    $subject = createOrderShipmentOptions([
        'options'         => [],
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromCheckoutData(['deliveryType' => 'standard', 'date' => '2099-08-20']),
        'resolved'        => ResolvedOptions::of([]),
    ]);

    expect($subject->shipmentOptions()->getDeliveryDate())->toBe('2099-08-20 00:00:00');
});
