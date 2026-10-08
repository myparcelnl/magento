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
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromCheckoutData(['deliveryType' => 'standard', 'date' => '2099-08-20']),
        'resolved'        => ResolvedOptions::of([]),
    ]);

    expect($subject->shipmentOptions()->getDeliveryDate())->toBe('2099-08-20 00:00:00');
});

/** The API refuses a delivery date for DPD and bpost, and together with collect. */
function deliveryDateSentFor(string $carrier, array $resolved = []): ?string
{
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE);

    return createOrderShipmentOptions([
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromCheckoutData(['carrier' => $carrier, 'deliveryType' => 'standard', 'date' => '2099-08-20']),
        'resolved'        => ResolvedOptions::of($resolved),
    ])->shipmentOptions()->getDeliveryDate();
}

it('sends no delivery date for a carrier that refuses one', function (string $carrier) {
    expect(deliveryDateSentFor($carrier))->toBeNull();
})->with(['dpd', 'bpost']);

it('sends no delivery date together with collect', function () {
    expect(deliveryDateSentFor('postnl', ['collect' => true]))->toBeNull()
        ->and(deliveryDateSentFor('postnl'))->toBe('2099-08-20 00:00:00');
});

it('sends no delivery date when none is stored', function () {
    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('getPackageType')->andReturn(PackageType::PACKAGE);

    $subject = createOrderShipmentOptions([
        'defaultOptions'  => $defaultOptions,
        'deliveryOptions' => DeliveryOptions::fromCheckoutData(['deliveryType' => 'standard', 'date' => '']),
        'resolved'        => ResolvedOptions::of([]),
    ]);

    expect($subject->shipmentOptions()->getDeliveryDate())->toBeNull();
});
