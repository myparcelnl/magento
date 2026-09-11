<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/*
 * hasUnverifiedCapabilities() drives the admin notice that the options on screen are the form's
 * own rather than the account's. A partial fallback is the case that matters.
 *
 * capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php,
 * createNewShipmentBlockWith() in Tests/Helpers/NewShipmentBlockMocks.php.
 */

/**
 * getFormCarriers() is the real path: the template resolves the whole form through it, then reads
 * the flag, so the flag is only meaningful after it has run. No insurance and no digital stamp in
 * these fixtures, which keeps DefaultOptions and Weight out of the picture.
 */
function resolveFormWith(array $byPackageType): NewShipment
{
    $block = createNewShipmentBlockWith($byPackageType);
    $block->getFormCarriers();

    return $block;
}

function plainResult(array $packageTypes, array $options): array
{
    return capabilityResult([
        'packageTypes' => $packageTypes,
        'options'      => $options,
        'collo'        => ['max' => 1],
    ]);
}

it('reports nothing unverified when every answer came from the account', function () {
    $answered = CapabilitySet::fromApiResults([
        plainResult(['PACKAGE', 'MAILBOX'], ['requiresSignature' => []]),
    ]);

    $block = resolveFormWith([
        ''                        => $answered,
        PackageType::PACKAGE_NAME => $answered,
        PackageType::MAILBOX_NAME => $answered,
    ]);

    expect($block->hasUnverifiedCapabilities())->toBeFalse();
});

it('reports unverified when only some package types answered', function () {
    $answered = CapabilitySet::fromApiResults([
        plainResult(['PACKAGE', 'MAILBOX'], ['requiresSignature' => []]),
    ]);

    $block = resolveFormWith([
        ''                        => $answered,
        PackageType::PACKAGE_NAME => $answered,
        PackageType::MAILBOX_NAME => CapabilitySet::permissive(),
    ]);

    $form    = $block->getFormCarriers();
    $byName  = array_column($form[0]['packageTypes'], 'options', 'name');

    expect($block->hasUnverifiedCapabilities())->toBeTrue()
        // and the fallback is visible in the data, not only in the flag: the mailbox offers
        // everything while the package offers the one option the account reported.
        ->and($byName[PackageType::PACKAGE_NAME])->toBe([ShipmentOption::SIGNATURE])
        ->and(count($byName[PackageType::MAILBOX_NAME]))->toBeGreaterThan(1);
});

it('reports unverified when the whole lookup fell back', function () {
    $block = resolveFormWith(array_fill_keys(
        array_merge([''], array_keys(PackageType::NAMES_IDS_MAP)),
        CapabilitySet::permissive()
    ));

    expect($block->hasUnverifiedCapabilities())->toBeTrue();
});
