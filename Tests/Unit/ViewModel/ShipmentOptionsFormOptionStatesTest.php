<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;

// createShipmentOptionsFormWith() lives in Tests/Helpers/ShipmentOptionsFormMocks.php, the capability
// fixtures in Tests/Helpers/CapabilitiesFixtures.php.

/** A PostNL package answer offering these options, by wire key. */
function postnlPackageOffering(array $options, array $deliveryTypes = ['STANDARD_DELIVERY', 'MORNING_DELIVERY', 'EVENING_DELIVERY', 'PICKUP_DELIVERY']): CapabilitySet
{
    return CapabilitySet::fromApiResults([capabilityResult([
        'packageTypes'  => ['PACKAGE'],
        'deliveryTypes' => $deliveryTypes,
        'options'       => array_fill_keys($options, ['isRequired' => false, 'isSelectedByDefault' => false]),
    ])]);
}

/**
 * A form for an NL order, with the package answer the carrier offers and the answers per filtered
 * shape. $ticked are the options DefaultOptions answers as on.
 *
 * @param array<string,CapabilitySet> $filtered
 */
function optionStatesForm(array $filtered, array $ticked = [], array $stored = ['deliveryType' => 'standard']): ShipmentOptionsForm
{
    $form = createShipmentOptionsFormWith(
        array_merge([
            ''                        => postnlPackageOffering(['requiresAgeVerification', 'requiresSignature', 'requiresReceiptCode']),
            PackageType::PACKAGE_NAME => postnlPackageOffering(['requiresAgeVerification', 'requiresSignature', 'requiresReceiptCode']),
        ], $filtered),
        ['getShippingAddress' => createAddress(), 'deliveryOptions' => json_encode($stored)]
    );

    getPrivateProperty($form, 'defaultOptions')->shouldReceive('hasOptionSet')->andReturnUsing(
        static fn(string $option): bool => in_array($option, $ticked, true)
    );

    return $form;
}

it('greys out an option the delivery type does not allow', function () {
    $form   = optionStatesForm(['package|signature|morning' => postnlPackageOffering(['requiresSignature'])]);
    $states = $form->optionStates('postnl', PackageType::PACKAGE_NAME, 'morning', [ShipmentOption::SIGNATURE]);

    expect($states[ShipmentOption::AGE_CHECK])->toBe(['checked' => false, 'disabled' => true, 'greyed' => true])
        ->and($states[ShipmentOption::SIGNATURE])->toBe(['checked' => true, 'disabled' => false, 'greyed' => false])
        ->and($form->emptyAnswer('postnl', PackageType::PACKAGE_NAME, 'morning', [ShipmentOption::SIGNATURE]))->toBeFalse();
});

it('greys out an option a ticked option excludes', function () {
    $form   = optionStatesForm(['package|age_check|standard' => postnlPackageOffering(['requiresAgeVerification', 'requiresSignature'])]);
    $states = $form->optionStates('postnl', PackageType::PACKAGE_NAME, 'standard', [ShipmentOption::AGE_CHECK]);

    expect($states[ShipmentOption::RECEIPT_CODE]['greyed'])->toBeTrue()
        ->and($states[ShipmentOption::AGE_CHECK]['checked'])->toBeTrue();
});

it('treats a default and a locked option as ticked', function () {
    $form = optionStatesForm(
        [PackageType::PACKAGE_NAME => CapabilitySet::fromApiResults([capabilityResult([
            'packageTypes' => ['PACKAGE'],
            'options'      => [
                'requiresSignature'       => ['isRequired' => true, 'isSelectedByDefault' => true],
                'requiresAgeVerification' => ['isRequired' => false, 'isSelectedByDefault' => false],
            ],
        ])])],
        [ShipmentOption::AGE_CHECK]
    );

    expect($form->tickedOptions('postnl', PackageType::PACKAGE_NAME))
        ->toEqualCanonicalizing([ShipmentOption::SIGNATURE, ShipmentOption::AGE_CHECK]);
});

it('falls back to the answer without options when the answer is empty', function () {
    $form   = optionStatesForm([
        'package|age_check|morning' => CapabilitySet::fromApiResults([]),
        'package||morning'          => postnlPackageOffering(['requiresSignature']),
    ]);
    $states = $form->optionStates('postnl', PackageType::PACKAGE_NAME, 'morning', [ShipmentOption::AGE_CHECK]);

    expect($form->emptyAnswer('postnl', PackageType::PACKAGE_NAME, 'morning', [ShipmentOption::AGE_CHECK]))->toBeTrue()
        ->and($states[ShipmentOption::SIGNATURE]['greyed'])->toBeFalse()
        ->and($states[ShipmentOption::AGE_CHECK]['greyed'])->toBeTrue();
});

it('lists the delivery types allowed with the ticked options', function () {
    $form = optionStatesForm(['package|age_check|' => postnlPackageOffering(['requiresAgeVerification'], ['STANDARD_DELIVERY', 'PICKUP_DELIVERY'])]);

    expect($form->allowedDeliveryTypes('postnl', PackageType::PACKAGE_NAME, [ShipmentOption::AGE_CHECK]))
        ->toEqualCanonicalizing(['standard', 'pickup']);
});

it('allows every delivery type the modal offers while capabilities are unknown', function () {
    $form = createShipmentOptionsFormWith(CapabilitySet::permissive(), ['getShippingAddress' => createAddress()]);

    expect($form->allowedDeliveryTypes('postnl', PackageType::PACKAGE_NAME, []))
        ->toEqualCanonicalizing(['standard', 'morning', 'evening', 'express', 'pickup']);
});

it('maps each option to the options it requires', function () {
    $form = optionStatesForm([PackageType::PACKAGE_NAME => CapabilitySet::fromApiResults([capabilityResult([
        'packageTypes' => ['PACKAGE'],
        'options'      => [
            'requiresAgeVerification' => ['requires' => ['recipientOnlyDelivery', 'requiresSignature'], 'isRequired' => false],
            'requiresSignature'       => ['isRequired' => false],
            'recipientOnlyDelivery'   => ['isRequired' => false],
        ],
    ])])]);

    expect($form->requiresMap('postnl', PackageType::PACKAGE_NAME)[ShipmentOption::AGE_CHECK])
        ->toEqualCanonicalizing([ShipmentOption::ONLY_RECIPIENT, ShipmentOption::SIGNATURE]);
});

it('gives the stored delivery as a list value', function (array $stored, string $value) {
    expect(optionStatesForm([], [], $stored)->storedDeliveryValue())->toBe($value);
})->with([
    'morning on a date' => [['deliveryType' => 'morning', 'date' => '2026-10-29 00:00:00'], 'morning|2026-10-29'],
    'standard, no date' => [['deliveryType' => 'standard'], 'standard|'],
    'pickup'            => [['deliveryType' => 'pickup', 'pickupLocation' => ['location_name' => 'Primera']], 'pickup|'],
    'nothing stored'    => [[], 'standard|'],
]);

it('summarises the delivery', function (array $stored, string $line) {
    expect(optionStatesForm([], [], $stored)->deliverySummary())->toBe($line);
})->with([
    'morning on a date' => [['deliveryType' => 'morning', 'date' => '2026-10-29 00:00:00'], 'Morning · 29-10-2026'],
    'standard, no date' => [['deliveryType' => 'standard'], 'Standard'],
    'pickup'            => [['deliveryType' => 'pickup', 'pickupLocation' => ['location_name' => 'Primera']], 'Pickup · Primera'],
]);

it('starts each option as its default before the first refresh', function () {
    $states = optionStatesForm([], [ShipmentOption::SIGNATURE])->initialOptionStates('postnl', PackageType::PACKAGE_NAME);

    expect($states[ShipmentOption::SIGNATURE])->toBe(['checked' => true, 'disabled' => false, 'greyed' => false])
        ->and($states[ShipmentOption::AGE_CHECK])->toBe(['checked' => false, 'disabled' => false, 'greyed' => false]);
});

it('gives the code of the stored pickup location', function () {
    $form = optionStatesForm([], [], ['deliveryType' => 'pickup', 'pickupLocation' => ['location_code' => '176227']]);

    expect($form->storedPickupLocationCode())->toBe('176227')
        ->and(optionStatesForm([])->storedPickupLocationCode())->toBeNull();
});

it('gives the stored delivery, and a home delivery for a pickup it cannot read', function () {
    $morning    = optionStatesForm([], [], ['carrier' => 'postnl', 'deliveryType' => 'morning', 'date' => '2026-10-29 00:00:00'])->storedDelivery();
    $unreadable = optionStatesForm([], [], ['carrier' => 'postnl', 'deliveryType' => 'pickup'])->storedDelivery();

    expect($morning->getDeliveryType())->toBe('morning')
        ->and($morning->getCarrier())->toBe('postnl')
        ->and($unreadable->isPickup())->toBeFalse();
});
