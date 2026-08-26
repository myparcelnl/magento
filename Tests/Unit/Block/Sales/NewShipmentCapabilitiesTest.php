<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;

// capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php.

/**
 * The constructor is skipped: it stands up a Magento backend block context and an ObjectManager
 * lookup, while these methods read only $order and the memoised $capabilities.
 *
 * $capabilities is either one CapabilitySet, pre-seeded for every shape so the shape distinction is
 * out of the way, or a map keyed the way the block keys it: '' for the package-type-agnostic
 * lookup, the module package type name otherwise. Any shape left unseeded would reach the
 * repository, which these tests do not set — so a fatal there means the block asked something the
 * test did not expect.
 *
 * @param CapabilitySet|array<string,CapabilitySet> $capabilities
 */
function createNewShipmentBlockWith($capabilities, array $orderOverrides = []): NewShipment
{
    if ($capabilities instanceof CapabilitySet) {
        $shapes       = array_merge([''], array_keys(PackageType::NAMES_IDS_MAP));
        $capabilities = array_fill_keys($shapes, $capabilities);
    }

    $block = newInstanceWithoutConstructor(NewShipment::class);

    setPrivateProperty($block, 'order', createOrder(array_merge([
        'deliveryOptions' => json_encode(['deliveryType' => 'standard']),
    ], $orderOverrides)));
    setPrivateProperty($block, 'capabilities', $capabilities);
    setPrivateProperty($block, 'form', new NewShipmentForm());

    return $block;
}

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

    expect($block->getCarriers())->toBe([Carrier::POSTNL, Carrier::DPD])
        ->and($block->getCarriers())->not->toContain(Carrier::TRUNKRS);
});

it('keeps the carriers in configuration order, not response order', function () {
    $reversed = CapabilitySet::fromApiResults([
        capabilityResult(['carrier' => 'TRUNKRS', 'packageTypes' => ['PACKAGE']]),
        capabilityResult(),
    ]);

    $configured = array_keys(Config::CARRIERS_XML_PATH_MAP);

    expect(array_search(Carrier::POSTNL, $block = createNewShipmentBlockWith($reversed)->getCarriers(), true))
        ->toBeLessThan(array_search(Carrier::TRUNKRS, $block, true))
        ->and(array_search(Carrier::POSTNL, $configured, true))
        ->toBeLessThan(array_search(Carrier::TRUNKRS, $configured, true));
});

it('ignores a reported carrier the module has no settings for', function () {
    // CHEAP_CARGO and UPS_EXPRESS_SAVER are real: a live account returned both.
    $set = CapabilitySet::fromApiResults([
        capabilityResult(),
        capabilityResult(['carrier' => 'CHEAP_CARGO', 'packageTypes' => ['PALLET']]),
        capabilityResult(['carrier' => 'UPS_EXPRESS_SAVER']),
    ]);

    expect(createNewShipmentBlockWith($set)->getCarriers())->toBe([Carrier::POSTNL])
        ->and($set->unknownValues()['carrier'])->toBe(['CHEAP_CARGO', 'UPS_EXPRESS_SAVER']);
});

it('offers every configured carrier when capabilities could not be reached', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::permissive());

    expect($block->getCarriers())->toBe(array_keys(Config::CARRIERS_XML_PATH_MAP));
});

it('offers the package types the account reports for that carrier', function () {
    $block = createNewShipmentBlockWith(postnlAndDpdCapabilities());

    expect($block->getPackageTypes(Carrier::POSTNL))->toBe([
        PackageType::PACKAGE_NAME,
        PackageType::MAILBOX_NAME,
        PackageType::DIGITAL_STAMP_NAME,
        PackageType::PACKAGE_SMALL_NAME,
    ])
        ->and($block->getPackageTypes(Carrier::DPD))->toBe([PackageType::PACKAGE_NAME]);
});

it('degrades to the package types this form has always offered', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::permissive());

    // The five in PACKAGE_TYPE_HUMAN_MAP: what the form showed before capabilities existed. Not
    // every type the module knows, so pallet and envelope stay off a form they never appeared on.
    expect($block->getPackageTypes(Carrier::POSTNL))->toBe([
        PackageType::PACKAGE_NAME,
        PackageType::MAILBOX_NAME,
        PackageType::LETTER_NAME,
        PackageType::DIGITAL_STAMP_NAME,
        PackageType::PACKAGE_SMALL_NAME,
    ])
        ->and($block->getPackageTypes(Carrier::POSTNL))->not->toContain(PackageType::PALLET_NAME);
});

it('every package type it offers has an id, so the form can submit it', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'HOVERCRAFT']]),
    ]);

    foreach (createNewShipmentBlockWith($set)->getPackageTypes(Carrier::POSTNL) as $name) {
        expect(PackageType::NAMES_IDS_MAP)->toHaveKey($name);
    }
});

it('renders the options the account reports, insurance excluded', function () {
    $block = createNewShipmentBlockWith(postnlAndDpdCapabilities());

    expect($block->getShipmentOptions(Carrier::POSTNL, PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::SIGNATURE)
        ->toContain(ShipmentOption::ONLY_RECIPIENT)
        ->not->toContain(ShipmentOption::INSURANCE)
        ->and($block->getShipmentOptions(Carrier::DPD, PackageType::PACKAGE_NAME))
        ->toBe([ShipmentOption::SIGNATURE]);
});

it('drops receipt code from a non-standard delivery even when the account has it', function () {
    $withReceiptCode = CapabilitySet::fromApiResults([
        capabilityResult(['options' => ['requiresReceiptCode' => [], 'requiresSignature' => []]]),
    ]);

    $standard = createNewShipmentBlockWith($withReceiptCode);
    $evening  = createNewShipmentBlockWith($withReceiptCode, [
        'deliveryOptions' => json_encode(['deliveryType' => 'evening']),
    ]);

    expect($standard->getShipmentOptions(Carrier::POSTNL, PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::RECEIPT_CODE)
        ->and($evening->getShipmentOptions(Carrier::POSTNL, PackageType::PACKAGE_NAME))
        ->not->toContain(ShipmentOption::RECEIPT_CODE)
        ->toContain(ShipmentOption::SIGNATURE);
});

it('offers the form its usual options when capabilities could not be reached', function () {
    $block = createNewShipmentBlockWith(CapabilitySet::permissive());

    expect($block->getShipmentOptions(Carrier::POSTNL, PackageType::PACKAGE_NAME))
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
        ->hasInsurance(Carrier::POSTNL, PackageType::PACKAGE_NAME))->toBeTrue()
        ->and(createNewShipmentBlockWith($noInsurance)
            ->hasInsurance(Carrier::POSTNL, PackageType::PACKAGE_NAME))->toBeFalse()
        ->and(createNewShipmentBlockWith(CapabilitySet::permissive())
            ->hasInsurance(Carrier::DPD, PackageType::PALLET_NAME))->toBeTrue();
});

it('asks nothing for an order with no shipping address rather than inventing a country', function () {
    $block = newInstanceWithoutConstructor(NewShipment::class);
    setPrivateProperty($block, 'order', createOrder(['getShippingAddress' => null]));
    // No capabilities and no repository set: reaching either would fatal, and that is the assertion.
    setPrivateProperty($block, 'form', new NewShipmentForm());

    expect($block->getCountry())->toBe('')
        ->and($block->getCarriers())->toBe(array_keys(Config::CARRIERS_XML_PATH_MAP));
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

    expect($block->getShipmentOptions(Carrier::POSTNL, PackageType::MAILBOX_NAME))
        ->toBe([ShipmentOption::PRIORITY_DELIVERY])
        ->and($block->hasInsurance(Carrier::POSTNL, PackageType::MAILBOX_NAME))->toBeFalse()
        ->and($block->getShipmentOptions(Carrier::POSTNL, PackageType::PACKAGE_NAME))
        ->toContain(ShipmentOption::LARGE_FORMAT)
        ->and($block->hasInsurance(Carrier::POSTNL, PackageType::PACKAGE_NAME))->toBeTrue();
});

it('still takes the carrier and package type lists from the broad answer', function () {
    // The broad call is the only one that can enumerate; a narrowed response only ever names the
    // package type it was asked about.
    $broad = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
    ]);

    $block = createNewShipmentBlockWith(['' => $broad]);

    expect($block->getCarriers())->toBe([Carrier::POSTNL])
        ->and($block->getPackageTypes(Carrier::POSTNL))
        ->toBe([PackageType::PACKAGE_NAME, PackageType::MAILBOX_NAME]);
});

it('withholds rather than over-reports for a package type it cannot express as a v2 name', function () {
    $block = createNewShipmentBlockWith(['' => CapabilitySet::fromApiResults([capabilityResult()])]);

    // 'hovercraft' has no v2 name, so no request can be built for it. Permissive, not the broad
    // superset, because the superset would claim options no one asked about.
    expect($block->getShipmentOptions(Carrier::POSTNL, 'hovercraft'))
        ->toBe(array_values(array_filter(
            ShipmentOption::TO_CHECK,
            static fn (string $o): bool => ShipmentOption::INSURANCE !== $o
        )));
});
