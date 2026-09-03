<?php

declare(strict_types=1);

use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptions;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
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
it('carries the date and pickup location it is given', function () {
    // The shape of a real stored pickup order. Until 2026-08 this returned null for
    // both, because the class this replaced assigned them from an undefined variable
    // ($originAdapter) that was never a constructor parameter — a bug from the file's
    // first commit in 2020, not a decision. `??` kept it silent.
    $options = DeliveryOptions::fromOrderFallback([
        'carrier'        => 'postnl',
        'date'           => '2026-09-05 10:00:00',
        'deliveryType'   => DeliveryType::PICKUP_NAME,
        'packageType'    => PackageType::PACKAGE_NAME,
        'isPickup'       => true,
        'pickupLocation' => [
            'location_name'     => 'Primera Sanders',
            'location_code'     => '216877',
            'retail_network_id' => 'PNPNL-01',
            'street'            => 'Polderplein',
            'number'            => '1',
            'postal_code'       => '2132BA',
            'city'              => 'HOOFDDORP',
            'cc'                => 'NL',
        ],
    ]);

    expect($options->getDate())->toBe('2026-09-05 10:00:00')
        ->and($options->isPickup())->toBeTrue()
        ->and($options->getPickupLocation())->not->toBeNull()
        ->and($options->getPickupLocation()->getLocationCode())->toBe('216877')
        ->and($options->getPickupLocation()->getRetailNetworkId())->toBe('PNPNL-01')
        ->and($options->getPickupLocation()->getPostalCode())->toBe('2132BA');
});

it('reads a pickup location the widget wrote in camelCase', function () {
    // Both spellings are in the database. The factory normalises before dispatching;
    // this constructor is handed raw stored data, so it must normalise for itself.
    $options = DeliveryOptions::fromOrderFallback([
        'carrier'        => 'postnl',
        'deliveryType'   => DeliveryType::PICKUP_NAME,
        'pickupLocation' => [
            'locationName'    => 'Primera Sanders',
            'locationCode'    => '216877',
            'retailNetworkId' => 'PNPNL-01',
            'postalCode'      => '2132BA',
        ],
    ]);

    expect($options->getPickupLocation()->getLocationName())->toBe('Primera Sanders')
        ->and($options->getPickupLocation()->getLocationCode())->toBe('216877')
        ->and($options->getPickupLocation()->getRetailNetworkId())->toBe('PNPNL-01')
        ->and($options->getPickupLocation()->getPostalCode())->toBe('2132BA');
});

it('leaves the date and pickup location null when the stored data has neither', function () {
    $options = DeliveryOptions::fromOrderFallback(['carrier' => 'postnl']);

    expect($options->getDate())->toBeNull()
        ->and($options->getPickupLocation())->toBeNull()
        ->and($options->isPickup())->toBeFalse();
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

it('normalises nested keys in every named constructor, not only through the factory', function () {
    // The invariant that DR-24 broke: whether nested keys are snake_cased used to
    // depend on the caller having gone through DeliveryOptionsFactory. Each named
    // constructor now does it for itself, so calling one directly is safe.
    $camelCase = [
        'deliveryType'   => DeliveryType::PICKUP_NAME,
        'pickupLocation' => ['locationCode' => '216877', 'postalCode' => '2132BA'],
        'shipmentOptions' => ['ageCheck' => true],
    ];

    $viaConstructor = DeliveryOptions::fromCheckoutData($camelCase);
    $viaFactory     = DeliveryOptionsFactory::create($camelCase);
    $viaFallback    = DeliveryOptions::fromOrderFallback($camelCase);

    expect($viaConstructor->getPickupLocation()->getLocationCode())->toBe('216877')
        ->and($viaFactory->getPickupLocation()->getLocationCode())->toBe('216877')
        ->and($viaFallback->getPickupLocation()->getLocationCode())->toBe('216877')
        ->and($viaConstructor->toArray())->toBe($viaFactory->toArray());
});
