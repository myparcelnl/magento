<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\PackageTypeResolver;

// capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php.

/**
 * The constructor is skipped: it reads the checkout session. checkPackageType() needs only the
 * quote, the config, the two cart services and the memoised capabilities.
 *
 * capabilityLookupWith() seeds the shapes; see its doc block for the key shape and for what an
 * unseeded shape does.
 *
 * $forced is what the order carries whatever the shopper picks; an empty list is the ordinary
 * order, which never reaches a narrowed lookup.
 *
 * What used to be three setters on a shared package object is now one PackageTypeCandidates handed
 * to the resolver, so the cases below read that instead.
 *
 * @return array{checkout: Checkout, packageTypes: PackageTypeResolver, cartRules: CartShippingRules, calls: object}
 */
function createCheckoutWith(array $capabilities, string $country = 'NL', array $forced = []): array
{
    $calls = new class {
        public ?PackageTypeCandidates $candidates = null;
    };

    $packageTypes = Mockery::mock(PackageTypeResolver::class);
    $packageTypes->shouldReceive('resolve')->andReturnUsing(
        static function (array $items, string $carrierName, string $country, PackageTypeCandidates $candidates) use ($calls): string {
            $calls->candidates = $candidates;

            return PackageType::PACKAGE_NAME;
        }
    )->byDefault();

    $cartRules = Mockery::mock(CartShippingRules::class);
    $cartRules->shouldReceive('forcedLimitingOptions')->andReturn($forced)->byDefault();
    $cartRules->shouldReceive('hidesDeliveryOptions')->andReturn(false)->byDefault();

    $config = createPermissiveConfig();

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getStoreId')->andReturn(1)->byDefault();

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'packageTypes', $packageTypes);
    setPrivateProperty($checkout, 'cartRules', $cartRules);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', $quote);
    setPrivateProperty($checkout, 'storeId', 1);
    setPrivateProperty($checkout, 'capabilityLookup', capabilityLookupWith($capabilities, $country, 1));

    return [
        'checkout'     => $checkout,
        'packageTypes' => $packageTypes,
        'cartRules'    => $cartRules,
        'calls'        => $calls,
    ];
}

/** Whether the candidates the resolver was handed include one package type. */
function candidateWasOffered(object $calls, string $packageTypeName): bool
{
    return null !== $calls->candidates && $calls->candidates->has($packageTypeName);
}

it('turns off a package type the account does not have, whatever configuration says', function () {
    // Config says yes to everything; only the contract should be able to say no.
    $mailboxOnly = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
    ]);

    $c = createCheckoutWith(['' => $mailboxOnly]);
    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect(candidateWasOffered($c['calls'], PackageType::MAILBOX_NAME))->toBeTrue()
        ->and(candidateWasOffered($c['calls'], PackageType::DIGITAL_STAMP_NAME))->toBeFalse()
        ->and(candidateWasOffered($c['calls'], PackageType::PACKAGE_SMALL_NAME))->toBeFalse();
});

it('leaves the decision to configuration when capabilities could not be reached', function () {
    $c = createCheckoutWith(['' => CapabilitySet::permissive()]);
    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect(candidateWasOffered($c['calls'], PackageType::MAILBOX_NAME))->toBeTrue()
        ->and(candidateWasOffered($c['calls'], PackageType::DIGITAL_STAMP_NAME))->toBeTrue()
        ->and(candidateWasOffered($c['calls'], PackageType::PACKAGE_SMALL_NAME))->toBeTrue();
});

it('asks only the package-type-agnostic question when the order forces nothing', function () {
    // Seeding only the agnostic shape proves nothing else is consulted: a narrowed lookup would
    // reach the repository, which is not set here, and fatal. That is the ordinary order, and it
    // is what keeps the extra calls off the common path.
    $c = createCheckoutWith(['' => CapabilitySet::fromApiResults([capabilityResult()])]);

    expect($c['checkout']->checkPackageType(Carrier::POSTNL, 'NL'))->toBe(PackageType::PACKAGE_NAME);
});

it('answers per carrier, not once for the store', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX', 'DIGITAL_STAMP']]),
        capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE']]),
    ]);

    $postnl = createCheckoutWith(['' => $set]);
    $postnl['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    $dpd = createCheckoutWith(['' => $set]);
    $dpd['checkout']->checkPackageType(Carrier::DPD, 'NL');

    expect(candidateWasOffered($postnl['calls'], PackageType::DIGITAL_STAMP_NAME))->toBeTrue()
        ->and(candidateWasOffered($dpd['calls'], PackageType::DIGITAL_STAMP_NAME))->toBeFalse();
});

it('rules out a package type that cannot carry an option the order forces on', function () {
    // An 18+ order cannot go as a mailbox when the account has no age check on a mailbox. The
    // agnostic answer still lists mailbox — only the narrowed one knows.
    $c = createCheckoutWith(
        [
            ''        => CapabilitySet::fromApiResults([capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']])]),
            'mailbox'    => CapabilitySet::fromApiResults([
                capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresSignature' => []]]),
            ]),
        ],
        'NL',
        [ShipmentOption::AGE_CHECK]
    );

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect(candidateWasOffered($c['calls'], PackageType::MAILBOX_NAME))->toBeFalse();
});

it('keeps a package type that can carry the forced option', function () {
    $c = createCheckoutWith(
        [
            ''        => CapabilitySet::fromApiResults([capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']])]),
            'mailbox'    => CapabilitySet::fromApiResults([
                capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresAgeVerification' => []]]),
            ]),
        ],
        'NL',
        [ShipmentOption::AGE_CHECK]
    );

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect(candidateWasOffered($c['calls'], PackageType::MAILBOX_NAME))->toBeTrue();
});

it('answers the forced-option question per carrier', function () {
    // DPD may offer an age check on a mailbox where PostNL does not, so the same 18+ order gets a
    // different answer per carrier. One narrowed set, two carriers, two outcomes.
    $capabilities = [
        ''        => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
            capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE', 'MAILBOX']]),
        ]),
        'mailbox'    => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresSignature' => []]]),
            capabilityResult([
                'carrier'      => 'DPD',
                'packageTypes' => ['MAILBOX'],
                'options'      => ['requiresAgeVerification' => []],
            ]),
        ]),
    ];

    $postnl = createCheckoutWith($capabilities, 'NL', [ShipmentOption::AGE_CHECK]);
    $postnl['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    $dpd = createCheckoutWith($capabilities, 'NL', [ShipmentOption::AGE_CHECK]);
    $dpd['checkout']->checkPackageType(Carrier::DPD, 'NL');

    expect(candidateWasOffered($postnl['calls'], PackageType::MAILBOX_NAME))->toBeFalse()
        ->and(candidateWasOffered($dpd['calls'], PackageType::MAILBOX_NAME))->toBeTrue();
});

it('restricts nothing when capabilities could not be reached, even with a forced option', function () {
    // Degrade, do not disappear: an unreachable capabilities service must not quietly reprice
    // every 18+ order as a package.
    // Seeding only the agnostic shape also proves no narrowed call is made: a permissive answer
    // means the service is unreachable, and asking it again per package type would only fail again.
    $c = createCheckoutWith(['' => CapabilitySet::permissive()], 'NL', [ShipmentOption::AGE_CHECK]);

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect(candidateWasOffered($c['calls'], PackageType::MAILBOX_NAME))->toBeTrue();
});

/**
 * getDeliveryData() is private and reaches for tax, delivery costs and a dozen config keys, so it
 * is driven with only the collaborators its carrier loop touches. What is asserted is one key.
 */
function deliveryDataAllowsOptionsFor(string $carrierName, array $capabilities, array $forced): bool
{
    $c            = createCheckoutWith($capabilities, 'NL', $forced);
    $checkout     = $c['checkout'];
    $packageTypes = $c['packageTypes'];
    $cartRules    = $c['cartRules'];

    $packageTypes->shouldReceive('maxMailboxWeight')->andReturn(2000.0)->byDefault();
    $packageTypes->shouldReceive('cartWeight')->andReturn(100.0)->byDefault();
    $cartRules->shouldReceive('allowsPriorityDelivery')->andReturn(false)->byDefault();
    $cartRules->shouldReceive('forcesAgeCheck')->andReturn(in_array(ShipmentOption::AGE_CHECK, $forced, true))->byDefault();
    $cartRules->shouldReceive('excludesParcelLockers')->andReturn(false)->byDefault();

    $tax = Mockery::mock(MyParcelNL\Magento\Service\Tax::class);
    $tax->shouldReceive('shippingPrice')->andReturn(0.0)->byDefault();
    setPrivateProperty($checkout, 'tax', $tax);

    $deliveryCosts = Mockery::mock(MyParcelNL\Magento\Service\DeliveryCosts::class);
    $deliveryCosts->shouldReceive('getBasePriceForClient')->andReturn(0.0)->byDefault();
    setPrivateProperty($checkout, 'deliveryCosts', $deliveryCosts);

    $data = invokePrivateMethod($checkout, 'getDeliveryData', [PackageType::MAILBOX_NAME, 'NL']);

    return $data['carrierSettings'][$carrierName]['allowDeliveryOptions'];
}

it('does not offer a carrier that cannot carry a forced option on the resolved package type', function () {
    // One package type serves the whole checkout, so DPD allowing an age check on a mailbox can
    // still land mailbox in front of a PostNL customer. PostNL is dropped instead of failing later.
    $capabilities = [
        // isPickupAllowed() re-resolves the package type, so the agnostic shape is reached too.
        ''        => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['PACKAGE']]),
            capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE']]),
        ]),
        'mailbox'    => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresSignature' => []]]),
            capabilityResult([
                'carrier'      => 'DPD',
                'packageTypes' => ['MAILBOX'],
                'options'      => ['requiresAgeVerification' => []],
            ]),
        ]),
    ];

    expect(deliveryDataAllowsOptionsFor(Carrier::POSTNL, $capabilities, [ShipmentOption::AGE_CHECK]))->toBeFalse()
        ->and(deliveryDataAllowsOptionsFor(Carrier::DPD, $capabilities, [ShipmentOption::AGE_CHECK]))->toBeTrue();
});
