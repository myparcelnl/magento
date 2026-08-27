<?php

declare(strict_types=1);

use MyParcelNL\Magento\Block\Sales\NewShipment;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DigitalStampWeight;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Model\Source\DefaultOptions;
use MyParcelNL\Magento\Model\Source\DigitalStampWeightOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;

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

    // getFormCarriers() reaches these whenever a shape reports insurance, which a permissive shape
    // always does.
    $defaults = Mockery::mock(DefaultOptions::class);
    $defaults->shouldReceive('getDefaultInsurance')->andReturn(0)->byDefault();
    $defaults->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0)->byDefault();
    setPrivateProperty($block, 'defaultOptions', $defaults);

    $weight = Mockery::mock(Weight::class);
    $weight->shouldReceive('convertToGrams')->andReturn(0)->byDefault();
    setPrivateProperty($block, 'weightService', $weight);

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

// ---- the unverified-capabilities notice ------------------------------------

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

// ---- digital stamp weight buckets ------------------------------------------

/** @param int $orderWeightGrams what getDigitalStampWeight() resolves to */
function createNewShipmentBlockWeighing(int $orderWeightGrams): NewShipment
{
    $block = newInstanceWithoutConstructor(NewShipment::class);

    $weight = Mockery::mock(\MyParcelNL\Magento\Service\Weight::class);
    $weight->shouldReceive('convertToGrams')->andReturn($orderWeightGrams);

    // A zero order weight falls through to the configured default, so pin that at zero too and the
    // weightless case stays genuinely weightless.
    $defaults = Mockery::mock(\MyParcelNL\Magento\Model\Source\DefaultOptions::class);
    $defaults->shouldReceive('getDigitalStampDefaultWeight')->andReturn(0);

    setPrivateProperty($block, 'order', createOrder(['getWeight' => (float) $orderWeightGrams]));
    setPrivateProperty($block, 'weightService', $weight);
    setPrivateProperty($block, 'defaultOptions', $defaults);

    return $block;
}

/** @return int[] values of the selected buckets */
function selectedWeights(NewShipment $block): array
{
    return array_column(
        array_filter($block->getDigitalStampWeightOptions(), static fn (array $o): bool => $o['selected']),
        'value'
    );
}

it('selects exactly one range, and the lightest one for a weightless order', function () {
    // 90g and 300g both send 200: the merged 50-350 range sends a weight inside itself rather than
    // its own boundary. The old form-local list sent 100 and 350 here, values ReplaceDpzRange had
    // already retired from the matching admin setting.
    foreach ([0 => 20, 15 => 20, 20 => 20, 25 => 50, 90 => 200, 300 => 200, 350 => 200, 1500 => 2000] as $grams => $expected) {
        expect(selectedWeights(createNewShipmentBlockWeighing($grams)))
            ->toBe([$expected], "an order of {$grams}g should send {$expected}g");
    }
});

it('selects nothing above the heaviest range rather than guessing', function () {
    expect(selectedWeights(createNewShipmentBlockWeighing(5000)))->toBe([]);
});

it('offers the no-standard-weight option first and never selected', function () {
    $options = createNewShipmentBlockWeighing(25)->getDigitalStampWeightOptions();

    expect($options[0]['value'])->toBe(DigitalStampWeight::NO_STANDARD_WEIGHT)
        ->and($options[0]['selected'])->toBeFalse()
        ->and(array_column($options, 'value'))->toBe([0, 20, 50, 200, 2000])
        ->and(array_column($options, 'value'))->not->toContain(100)
        ->and(array_column($options, 'value'))->not->toContain(350);
});

it('offers the admin setting and the form the identical set of weights', function () {
    // They held separate lists until 2026-08, and the form's still carried the retired values.
    $setting = new DigitalStampWeightOptions(Mockery::mock(Config::class));

    expect(array_column($setting->toOptionArray(), 'value'))
        ->toBe(array_column(createNewShipmentBlockWeighing(0)->getDigitalStampWeightOptions(), 'value'));
});
