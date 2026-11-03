<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

function acceptanceCapabilitySet(): CapabilitySet
{
    return CapabilitySet::fromApiResults(acceptanceCapabilitiesResults());
}

it('reads requires and excludes in module names', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor('postnl', PackageType::PACKAGE_NAME, ShipmentOption::AGE_CHECK))
        ->toEqualCanonicalizing([ShipmentOption::ONLY_RECIPIENT, ShipmentOption::SIGNATURE])
        ->and($set->excludesFor('postnl', PackageType::PACKAGE_NAME, ShipmentOption::AGE_CHECK))
        ->toEqualCanonicalizing([ShipmentOption::PRINTERLESS_RETURN, ShipmentOption::RECEIPT_CODE]);
});

it('reads the pairings per carrier', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor('upsstandard', null, ShipmentOption::AGE_CHECK))->toBe([ShipmentOption::SIGNATURE])
        ->and($set->excludesFor('upsstandard', null, ShipmentOption::AGE_CHECK))->toBe([ShipmentOption::ONLY_RECIPIENT]);
});

it('answers no dependencies for an option the carrier does not list', function () {
    $set = acceptanceCapabilitySet();

    expect($set->requiresFor('postnl', null, 'hovercraft'))->toBe([])
        ->and($set->excludesFor('dpd', null, ShipmentOption::AGE_CHECK))->toBe([]);
});

it('answers no dependencies when permissive, so a failed lookup forces nothing', function () {
    $set = CapabilitySet::permissive();

    expect($set->requiresFor('postnl', null, ShipmentOption::AGE_CHECK))->toBe([])
        ->and($set->excludesFor('postnl', null, ShipmentOption::AGE_CHECK))->toBe([]);
});

it('derives a dependency name the module does not know, rather than dropping it', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => ['requires' => ['noTracking']]]]),
    ]);

    expect($set->requiresFor('postnl', null, ShipmentOption::SIGNATURE))->toBe(['no_tracking']);
});

it('drops each unreadable dependency entry and says so', function () {
    mockLoggerFacade()->shouldReceive('notice')->twice()->with(Mockery::pattern('/excludes of requiresSignature/'));

    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => ['excludes' => ['recipientOnlyDelivery', 42, '']]]]),
    ]);

    expect($set->excludesFor('postnl', null, ShipmentOption::SIGNATURE))->toBe([ShipmentOption::ONLY_RECIPIENT]);
});

it('lists every offered option, including one the module has no constant for', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => [], 'noTracking' => []]]),
    ]);

    expect($set->optionsFor('postnl', null))->toBe([ShipmentOption::SIGNATURE, 'no_tracking']);
});
