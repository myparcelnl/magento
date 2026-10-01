<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

// capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php,
// createNewShipmentBlockWith() in Tests/Helpers/NewShipmentBlockMocks.php.


function postnlAndDpdCapabilities(): CapabilitySet
{
    return CapabilitySet::fromApiResults([
        capabilityResult(),
        capabilityResult([
            'carrier'      => 'DPD',
            'packageTypes' => ['PACKAGE'],
            'options'      => ['requiresSignature' => []],
            'collo'        => ['max' => 1],
        ]),
    ]);
}

it('offers only the carriers the account has a contract for', function () {
    $block = createNewShipmentBlockWith(postnlAndDpdCapabilities());

    expect($block->getCarriers())->toBe(['postnl', 'dpd'])
        ->and($block->getCarriers())->not->toContain('trunkrs');
});

it('keeps the carriers in the order capabilities report them', function () {
    $reversed = CapabilitySet::fromApiResults([
        capabilityResult(['carrier' => 'TRUNKRS', 'packageTypes' => ['PACKAGE']]),
        capabilityResult(),
    ]);

    expect(createNewShipmentBlockWith($reversed)->getCarriers())->toBe(['trunkrs', 'postnl']);
});

it('offers a reported carrier the SDK knows, and ignores one it does not', function () {
    // CHEAP_CARGO and UPS_EXPRESS_SAVER are real: a live account returned both.
    $set = CapabilitySet::fromApiResults([
        capabilityResult(),
        capabilityResult(['carrier' => 'CHEAP_CARGO', 'packageTypes' => ['PALLET']]),
        capabilityResult(['carrier' => 'UPS_EXPRESS_SAVER']),
        capabilityResult(['carrier' => 'HOOPLA']),
    ]);

    expect(createNewShipmentBlockWith($set)->getCarriers())->toBe(['postnl', 'cheapcargo', 'upsexpresssaver'])
        ->and($set->unknownValues()['carrier'])->toBe(['HOOPLA']);
});

it('falls back to the exportable carriers of the stored contract when capabilities could not be reached', function () {
    // The live lookup answers permissive on any API failure. The stored contract is what the checkout
    // and the settings form read, so the three agree and a blip does not block label creation.
    $block = createNewShipmentBlockWith(CapabilitySet::permissive(), [], ['DPD', 'HOOPLA', 'POSTNL']);

    expect($block->getCarriers())->toBe(['dpd', 'postnl'])
        ->and($block->hasUnverifiedCapabilities())->toBeTrue();
});

it('offers no carrier when neither capabilities nor the stored contract could be read', function () {
    // The form's own warning says why; the module lists no carrier to fall back on.
    expect(createNewShipmentBlockWith(CapabilitySet::permissive())->getCarriers())->toBe([]);
});

it('offers the package types the account reports for that carrier', function () {
    $block = createNewShipmentBlockWith(postnlAndDpdCapabilities());

    expect($block->getPackageTypes('postnl'))->toBe([
        PackageType::PACKAGE_NAME,
        PackageType::MAILBOX_NAME,
        PackageType::DIGITAL_STAMP_NAME,
        PackageType::PACKAGE_SMALL_NAME,
    ])
        ->and($block->getPackageTypes('dpd'))->toBe([PackageType::PACKAGE_NAME]);
});

it('degrades to the package types this form has always offered', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::permissive());

    // The five in PACKAGE_TYPE_HUMAN_MAP: what the form showed before capabilities existed. Not
    // every type the module knows, so pallet and envelope stay off a form they never appeared on.
    expect($block->getPackageTypes('postnl'))->toBe([
        PackageType::PACKAGE_NAME,
        PackageType::MAILBOX_NAME,
        PackageType::LETTER_NAME,
        PackageType::DIGITAL_STAMP_NAME,
        PackageType::PACKAGE_SMALL_NAME,
    ])
        ->and($block->getPackageTypes('postnl'))->not->toContain(PackageType::PALLET_NAME);
});

it('every package type it offers has an id, so the form can submit it', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'HOVERCRAFT']]),
    ]);

    foreach (createNewShipmentBlockWith($set)->getPackageTypes('postnl') as $name) {
        expect(PackageType::NAMES_IDS_MAP)->toHaveKey($name);
    }
});

it('renders the options the account reports, insurance excluded', function () {
    $block = createNewShipmentBlockWith(postnlAndDpdCapabilities());

    expect($block->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::SIGNATURE)
        ->toContain(ShipmentOption::ONLY_RECIPIENT)
        ->not->toContain(ShipmentOption::INSURANCE)
        ->and($block->getShipmentOptions('dpd', PackageType::PACKAGE_NAME))
        ->toBe([ShipmentOption::SIGNATURE]);
});

it('renders an option only capabilities name, under a label read from its name', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => [], 'noTracking' => []]]),
    ]));

    expect($block->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->toBe([ShipmentOption::SIGNATURE, 'no_tracking'])
        ->and((new NewShipmentForm())->labelFor('no_tracking'))->toBe('No tracking')
        ->and((new NewShipmentForm())->labelFor(ShipmentOption::SIGNATURE))->toBe('Signature on receipt');
});

it('drops receipt code from a non-standard delivery even when the account has it', function () {
    $withReceiptCode = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresReceiptCode' => [], 'requiresSignature' => []]]),
    ]);

    $standard = createNewShipmentBlockWith($withReceiptCode);
    $evening  = createNewShipmentBlockWith($withReceiptCode, [
        'deliveryOptions' => json_encode(['deliveryType' => 'evening']),
    ]);

    expect($standard->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::RECEIPT_CODE)
        ->and($evening->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->not->toContain(ShipmentOption::RECEIPT_CODE)
        ->toContain(ShipmentOption::SIGNATURE);
});

it('offers the form its usual options when capabilities could not be reached', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::permissive());

    expect($block->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->toBe(array_values(array_filter(
            ShipmentOption::TO_CHECK,
            static fn (string $o): bool => ShipmentOption::INSURANCE !== $o
        )));
});

it('shows the insurance selector only where the account has insurance', function () {
    $noInsurance = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresSignature' => []]]),
    ]);

    expect(createNewShipmentBlockWith(postnlAndDpdCapabilities())
        ->hasInsurance('postnl', PackageType::PACKAGE_NAME))->toBeTrue()
        ->and(createNewShipmentBlockWith($noInsurance)
            ->hasInsurance('postnl', PackageType::PACKAGE_NAME))->toBeFalse()
        ->and(createNewShipmentBlockWith(CapabilitySet::permissive())
            ->hasInsurance('dpd', PackageType::PALLET_NAME))->toBeTrue();
});

it('offers the contract range as the field bounds, not a list of tiers', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::fromApiResults([capabilityResult()]));

    $insurance = null;

    foreach ($block->getFormCarriers() as $carrier) {
        foreach ($carrier['packageTypes'] as $packageType) {
            if (PackageType::PACKAGE_NAME === $packageType['name']) {
                $insurance = $packageType['insurance'];
            }
        }
    }

    expect($insurance)->toBe([
        'default'  => 0,
        'min'      => 0,
        'max'      => 5000,
        'floor'    => 0,
        'required' => false,
    ]);
});

it('leaves the field unbounded when the account named no insurance bounds', function () {
    $withoutBounds = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['insurance' => ['isRequired' => false]]]),
    ]);

    $block     = createNewShipmentBlockWith($withoutBounds);
    $insurance = null;

    foreach ($block->getFormCarriers() as $carrier) {
        foreach ($carrier['packageTypes'] as $packageType) {
            if (PackageType::PACKAGE_NAME === $packageType['name']) {
                $insurance = $packageType['insurance'];
            }
        }
    }

    expect($insurance)->toBe([
        'default'  => 0,
        'min'      => null,
        'max'      => null,
        'floor'    => 0,
        'required' => false,
    ]);
});

it('floors the form at the minimum when the contract requires insurance', function () {
    $required = CapabilitySet::fromApiResults([
        capabilityResult(['options' => capabilityOptions(['insurance' => [
            'isRequired' => true,
            'min'        => ['amount' => 10000],
            'max'        => ['amount' => 250000],
        ]])]),
    ]);

    $block     = createNewShipmentBlockWith($required);
    $insurance = null;

    foreach ($block->getFormCarriers() as $carrier) {
        foreach ($carrier['packageTypes'] as $packageType) {
            if (PackageType::PACKAGE_NAME === $packageType['name']) {
                $insurance = $packageType['insurance'];
            }
        }
    }

    expect($insurance['floor'])->toBe(100)
        ->and($insurance['required'])->toBeTrue();
});

it('asks nothing for an order with no shipping address rather than inventing a country', function () {
    // Nothing seeded and no repository behind the lookup: an order with a country would fatal on
    // reaching the repository, and that is the assertion.
    $block = createNewShipmentBlockWith([], ['getShippingAddress' => null]);

    expect($block->getCountry())->toBe('')
        ->and($block->getCarriers())->toBe([]);
});

it('asks per package type, so a mailbox does not inherit a package\'s options', function () {
    // The bug this pins: a package-type-agnostic response groups every package type of a carrier
    // into one result carrying the union of their options. Read as a matrix it says a mailbox may
    // be oversized and insured. It must be asked about on its own.
    $broadSuperset = CapabilitySet::fromApiResults([
        capabilityResult([
            'packageTypes' => ['PACKAGE', 'MAILBOX'],
            'options'      => [
                'requiresSignature'     => [],
                'recipientOnlyDelivery' => [],
                'oversizedPackage'      => [],
                'insurance'             => [],
            ],
            'collo'        => ['max' => 20],
        ]),
    ]);

    $mailboxOnly = CapabilitySet::fromApiResults([
        capabilityResult([
            'packageTypes' => ['MAILBOX'],
            'options'      => ['priorityDelivery' => []],
            'collo'        => ['max' => 1],
        ]),
    ]);

    $block = createNewShipmentBlockWith([
        ''                            => $broadSuperset,
        PackageType::PACKAGE_NAME     => $broadSuperset,
        PackageType::MAILBOX_NAME     => $mailboxOnly,
    ]);

    expect($block->getShipmentOptions('postnl', PackageType::MAILBOX_NAME))
        ->toBe([ShipmentOption::PRIORITY_DELIVERY])
        ->and($block->hasInsurance('postnl', PackageType::MAILBOX_NAME))->toBeFalse()
        ->and($block->getShipmentOptions('postnl', PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::LARGE_FORMAT)
        ->and($block->hasInsurance('postnl', PackageType::PACKAGE_NAME))->toBeTrue();
});

it('still takes the carrier and package type lists from the broad answer', function () {
    // The broad call is the only one that can enumerate; a narrowed response only ever names the
    // package type it was asked about.
    $broad = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
    ]);

    $block = createNewShipmentBlockWith(['' => $broad]);

    expect($block->getCarriers())->toBe(['postnl'])
        ->and($block->getPackageTypes('postnl'))
        ->toBe([PackageType::PACKAGE_NAME, PackageType::MAILBOX_NAME]);
});

it('withholds rather than over-reports for a package type it cannot express as a v2 name', function () {
    $block = createNewShipmentBlockWith(['' => CapabilitySet::fromApiResults([capabilityResult()])]);

    // 'hovercraft' has no v2 name, so no request can be built for it. Permissive, not the broad
    // superset, because the superset would claim options no one asked about.
    expect($block->getShipmentOptions('postnl', 'hovercraft'))
        ->toBe(array_values(array_filter(
            ShipmentOption::TO_CHECK,
            static fn (string $o): bool => ShipmentOption::INSURANCE !== $o
        )));
});
