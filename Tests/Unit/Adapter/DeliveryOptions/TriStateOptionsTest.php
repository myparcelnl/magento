<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;

/**
 * A stored option is null (inherit), true (on) or false (off). The checkout widget writes false for
 * an option it offered but the customer did not tick, so every checkout write turns that into null.
 */
it('turns an option the customer did not tick into inherit, and keeps every other key', function () {
    $widget = [
        'carrier'         => 'postnl',
        'deliveryType'    => DeliveryType::STANDARD_NAME,
        'unknownKey'      => 'kept',
        'shipmentOptions' => ['signature' => false, 'onlyRecipient' => true],
    ];

    expect(DeliveryOptions::inheritUnticked($widget))->toBe([
        'carrier'         => 'postnl',
        'deliveryType'    => DeliveryType::STANDARD_NAME,
        'unknownKey'      => 'kept',
        'shipmentOptions' => ['signature' => null, 'onlyRecipient' => true],
    ]);
});

it('leaves data without shipment options alone', function () {
    expect(DeliveryOptions::inheritUnticked(['deliveryType' => 'standard']))->toBe(['deliveryType' => 'standard'])
        ->and(DeliveryOptions::inheritUnticked(['shipmentOptions' => null]))->toBe(['shipmentOptions' => null]);
});

it('keeps a stored false as off when an order is read', function () {
    $options = DeliveryOptions::fromCheckoutData([
        'carrier'         => 'postnl',
        'deliveryType'    => DeliveryType::STANDARD_NAME,
        'shipmentOptions' => ['signature' => false],
    ]);

    expect($options->getShipmentOptions()->hasSignature())->toBeFalse();
});

it('reads a legacy false as inherit, because the legacy checkout wrote it for not ticked', function () {
    $options = ShipmentOptions::fromLegacyCheckoutData(['signature' => false, 'only_recipient' => true, 'insurance' => 0]);

    expect($options->hasSignature())->toBeNull()
        ->and($options->hasOnlyRecipient())->toBeTrue()
        ->and($options->getInsurance())->toBeNull();
});

it('leaves label amount and digital stamp weight out of toArray until they are set', function () {
    $data = ['carrier' => 'postnl', 'deliveryType' => DeliveryType::STANDARD_NAME];

    expect(array_keys(DeliveryOptions::fromCheckoutData($data)->toArray()))->toBe([
        'carrier', 'date', 'deliveryType', 'packageType', 'isPickup', 'pickupLocation', 'shipmentOptions',
    ]);

    $set = DeliveryOptions::fromCheckoutData($data + ['labelAmount' => 2, 'digitalStampWeight' => 50]);

    expect($set->getLabelAmount())->toBe(2)
        ->and($set->getDigitalStampWeight())->toBe(50)
        ->and(array_slice($set->toArray(), -2, 2, true))->toBe(['labelAmount' => 2, 'digitalStampWeight' => 50]);
});

it('keeps the dimensions a merchant saved, appended last, and drops one that is not a positive number', function () {
    $options = DeliveryOptions::fromCheckoutData([
        'deliveryType'       => DeliveryType::STANDARD_NAME,
        'labelAmount'        => 2,
        'physicalProperties' => ['length' => 40, 'width' => '30', 'height' => 0],
    ]);

    expect($options->getDimensions())->toBe(['length' => 40, 'width' => 30])
        ->and(array_slice($options->toArray(), -2, 2, true))
        ->toBe(['labelAmount' => 2, 'physicalProperties' => ['length' => 40, 'width' => 30]]);
});
