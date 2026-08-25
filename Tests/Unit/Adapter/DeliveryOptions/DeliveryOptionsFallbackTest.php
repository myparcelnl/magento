<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * The two named constructors the factory never reaches. Neither agrees with the checkout shapes on
 * defaults, so each is pinned here.
 */
it('carries what the fallback shape can carry', function () {
    $options = DeliveryOptions::fromOrderFallback([
        'carrier'      => 'dpd',
        'deliveryType' => DeliveryType::EVENING_NAME,
        'packageType'  => PackageType::MAILBOX_NAME,
    ]);

    expect($options->getCarrier())->toBe('dpd')
        ->and($options->getDeliveryType())->toBe(DeliveryType::EVENING_NAME)
        ->and($options->getDeliveryTypeId())->toBe(DeliveryType::EVENING)
        ->and($options->getPackageType())->toBe(PackageType::MAILBOX_NAME);
});

/**
 * Inherited, not intended: the class this replaced read both fields from an undefined variable.
 * Pinned so a later change to it is deliberate.
 */
it('never carries a date or a pickup location on the fallback shape', function () {
    $options = DeliveryOptions::fromOrderFallback([
        'carrier'        => 'postnl',
        'date'           => '2026-09-05 10:00:00',
        'deliveryType'   => DeliveryType::PICKUP_NAME,
        'pickupLocation' => ['location_name' => 'Primera', 'location_code' => '1'],
    ]);

    expect($options->getDate())->toBeNull()
        ->and($options->getPickupLocation())->toBeNull()
        ->and($options->isPickup())->toBeTrue();
});

/** Not posted means 'not chosen' here, so false — except the four the form never carries at all. */
it('flattens the options an admin posts and leaves the rest unsaid', function () {
    expect(ShipmentOptions::fromMagentoOptions([])->toArray())->toBe([
        'signature'         => false,
        'collect'           => false,
        'receipt_code'      => false,
        'insurance'         => 0,
        'age_check'         => false,
        'only_recipient'    => false,
        'return'            => false,
        'same_day_delivery' => null,
        'large_format'      => false,
        'label_description' => null,
        'hide_sender'       => null,
        'extra_assurance'   => null,
        'priority_delivery' => false,
    ]);
});

it('reads the options an admin did post', function () {
    $options = ShipmentOptions::fromMagentoOptions([
        ShipmentOption::SIGNATURE         => '1',
        ShipmentOption::AGE_CHECK         => true,
        ShipmentOption::INSURANCE         => '500',
        ShipmentOption::PRIORITY_DELIVERY => true,
    ]);

    expect($options->hasSignature())->toBeTrue()
        ->and($options->hasAgeCheck())->toBeTrue()
        ->and($options->getInsurance())->toBe(500)
        ->and($options->hasPriorityDelivery())->toBeTrue()
        ->and($options->hasCollect())->toBeFalse();
});

/** The checkout shapes keep null: an old order may genuinely not have had the option. */
it('keeps an unstored checkout option null rather than false', function () {
    expect(ShipmentOptions::fromCheckoutData([])->hasSignature())->toBeNull()
        ->and(ShipmentOptions::none()->getInsurance())->toBeNull()
        ->and(ShipmentOptions::fromLegacyCheckoutData([])->hasAgeCheck())->toBeNull();
});
