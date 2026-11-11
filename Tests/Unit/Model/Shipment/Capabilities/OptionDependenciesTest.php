<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

function acceptanceCapabilitySet(): CapabilitySet
{
    return CapabilitySet::fromApiResults(acceptanceCapabilitiesResults());
}

it('reads requires and excludes in module names', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::AGE_CHECK))
        ->toEqualCanonicalizing([ShipmentOption::ONLY_RECIPIENT, ShipmentOption::SIGNATURE])
        ->and($set->excludesFor(Carrier::POSTNL, PackageType::PACKAGE_NAME, ShipmentOption::AGE_CHECK))
        ->toEqualCanonicalizing([ShipmentOption::PRINTERLESS_RETURN, ShipmentOption::RECEIPT_CODE]);
});

it('reads the pairings per carrier', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor(Carrier::UPS_STANDARD, null, ShipmentOption::AGE_CHECK))->toBe([ShipmentOption::SIGNATURE])
        ->and($set->excludesFor(Carrier::UPS_STANDARD, null, ShipmentOption::AGE_CHECK))->toBe([ShipmentOption::ONLY_RECIPIENT]);
});

it('answers no dependencies for an option the carrier does not list', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor(Carrier::POSTNL, null, 'hovercraft'))->toBe([])
        ->and($set->excludesFor(Carrier::DPD, null, ShipmentOption::AGE_CHECK))->toBe([]);
});

it('answers no dependencies when permissive, so a failed lookup forces nothing', function () {
    $set = CapabilitySet::permissive();

    expect($set->requiresFor(Carrier::POSTNL, null, ShipmentOption::AGE_CHECK))->toBe([])
        ->and($set->excludesFor(Carrier::POSTNL, null, ShipmentOption::AGE_CHECK))->toBe([]);
});

it('derives a dependency name the module does not know, rather than dropping it', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => ['requires' => ['noTracking']]]]),
    ]);

    expect($set->requiresFor(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBe(['no_tracking']);
});

it('drops a dependency entry that is not an option the SDK knows', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => [
            'requires' => ['teleport'],
            'excludes' => ['recipientOnlyDelivery', 42, '', 'teleport'],
        ]]]),
    ]);

    // The option stays offered: the merchant switches it off by hand until the SDK knows teleport.
    expect($set->excludesFor(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBe([ShipmentOption::ONLY_RECIPIENT])
        ->and($set->requiresFor(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBe([])
        ->and($set->hasOption(Carrier::POSTNL, null, ShipmentOption::SIGNATURE))->toBeTrue();
});

it('lists every offered option, including one the module has no constant for', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => [], 'noTracking' => []]]),
    ]);

    // In the SDK model's property order, not the response's.
    expect($set->optionsFor(Carrier::POSTNL, null))->toEqualCanonicalizing([ShipmentOption::SIGNATURE, 'no_tracking']);
});
