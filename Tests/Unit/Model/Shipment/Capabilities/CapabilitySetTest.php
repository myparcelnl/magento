<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

// Fixtures live in Tests/Helpers/CapabilitiesFixtures.php.

it('translates v2 names to the module vocabulary', function () {
    $set = CapabilitySet::fromApiResults([capabilityResult()]);

    expect($set->carriers())->toBe([Carrier::POSTNL])
        ->and($set->packageTypesFor(Carrier::POSTNL))->toBe([
            PackageType::PACKAGE_NAME,
            PackageType::MAILBOX_NAME,
            PackageType::DIGITAL_STAMP_NAME,
            PackageType::PACKAGE_SMALL_NAME,
        ])
        ->and($set->deliveryTypesFor(Carrier::POSTNL))->toContain(DeliveryType::EVENING_NAME)
        ->and($set->optionsFor(Carrier::POSTNL))->toContain(ShipmentOption::SIGNATURE);
});

it('narrows an answer to the results matching a package type', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult([
            'packageTypes' => ['PACKAGE'],
            'options'      => ['requiresSignature' => [], 'requiresAgeVerification' => []],
        ]),
        capabilityResult([
            'packageTypes' => ['MAILBOX'],
            'options'      => ['priorityDelivery' => []],
        ]),
    ]);

    expect($set->hasOption(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::AGE_CHECK))->toBeTrue()
        ->and($set->hasOption(Carrier::POSTNL, PackageType::MAILBOX_NAME, ShipmentOption::AGE_CHECK))->toBeFalse()
        ->and($set->hasOption(Carrier::POSTNL, PackageType::MAILBOX_NAME, ShipmentOption::PRIORITY_DELIVERY))->toBeTrue()
        ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::PRIORITY_DELIVERY))->toBeTrue();
});

it('answers nothing for a carrier the account has no contract for', function () {
    $set = CapabilitySet::fromApiResults([capabilityResult()]);

    expect($set->carriers())->not->toContain(Carrier::DPD)
        ->and($set->packageTypesFor(Carrier::DPD))->toBe([])
        ->and($set->hasOption(Carrier::DPD, null, ShipmentOption::SIGNATURE))->toBeFalse();
});

it('keeps an unrecognised value and reports it instead of dropping it', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult([
            'packageTypes'  => ['PACKAGE', 'HOVERCRAFT'],
            'deliveryTypes' => ['STANDARD_DELIVERY', 'TELEPORT_DELIVERY'],
            'options'       => ['requiresSignature' => [], 'aBrandNewOption' => []],
        ]),
        capabilityResult(['carrier' => 'FUTURE_CARRIER']),
    ]);

    $unknown = $set->unknownValues();

    expect($unknown['packageType'])->toBe(['HOVERCRAFT'])
        ->and($unknown['deliveryType'])->toBe(['TELEPORT_DELIVERY'])
        ->and($unknown['option'])->toBe(['aBrandNewOption'])
        ->and($unknown['carrier'])->toBe(['FUTURE_CARRIER'])
        ->and($set->packageTypesFor(Carrier::POSTNL))->toBe([PackageType::PACKAGE_NAME]);
});

it('reads an option value verbatim, so insurance bounds survive', function () {
    $set = CapabilitySet::fromApiResults([capabilityResult()]);

    $insurance = $set->optionValue(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::INSURANCE);

    expect($insurance['min']['amount'])->toBe(0)
        ->and($insurance['max']['amount'])->toBe(500000)
        ->and($insurance['default']['amount'])->toBe(10000);
});

it('reports the collo maximum, taking the highest of the matching results', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE'], 'collo' => ['max' => 10]]),
        capabilityResult(['packageTypes' => ['PACKAGE'], 'collo' => ['max' => 4]]),
        capabilityResult(['packageTypes' => ['MAILBOX'], 'collo' => ['max' => 1]]),
    ]);

    expect($set->colloMaxFor(Carrier::POSTNL, PackageType::PACKAGE_NAME))->toBe(10)
        ->and($set->colloMaxFor(Carrier::POSTNL, PackageType::MAILBOX_NAME))->toBe(1);
});

it('survives a result missing every key it reads', function () {
    $set = CapabilitySet::fromApiResults([[], ['carrier' => 'POSTNL'], ['options' => 'not-an-array']]);

    expect($set->carriers())->toBe([Carrier::POSTNL])
        ->and($set->packageTypesFor(Carrier::POSTNL))->toBe([])
        ->and($set->colloMaxFor(Carrier::POSTNL))->toBeNull()
        ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBeFalse();
});

it('offers everything when permissive, which is not the same as an empty response', function () {
    $permissive = CapabilitySet::permissive();
    $empty      = CapabilitySet::fromApiResults([]);

    expect($permissive->isPermissive())->toBeTrue()
        ->and($permissive->hasOption(Carrier::DPD, PackageType::PALLET_NAME, ShipmentOption::AGE_CHECK))->toBeTrue()
        ->and($permissive->colloMaxFor(Carrier::DPD))->toBeNull()
        ->and($empty->isPermissive())->toBeFalse()
        ->and($empty->hasOption(Carrier::DPD, null, ShipmentOption::AGE_CHECK))->toBeFalse();
});
