<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;

// capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php.

/**
 * The constructor is skipped: it reads the checkout session. checkPackageType() needs only the
 * quote, the config, the package repository and the memoised capabilities.
 *
 * $capabilities is keyed the way Checkout keys it: "<country>|<packageType>", with an empty package
 * type for the shape-agnostic lookup.
 *
 * $forced is what the order carries whatever the shopper picks; an empty list is the ordinary
 * order, which never reaches a narrowed lookup.
 *
 * @return array{checkout: Checkout, package: PackageRepository, calls: object}
 */
function createCheckoutWith(array $capabilities, string $country = 'NL', array $forced = []): array
{
    $calls = new class {
        public array $activated = [];
    };

    $package = Mockery::mock(PackageRepository::class);
    foreach (['setMailboxSettings', 'setDigitalStampSettings', 'setPackageSmallSettings', 'setCurrentCountry'] as $noop) {
        $package->shouldReceive($noop)->byDefault();
    }
    foreach (['setMailboxActive', 'setDigitalStampActive', 'setPackageSmallActive'] as $setter) {
        $package->shouldReceive($setter)->andReturnUsing(
            static function ($on) use ($calls, $setter) {
                $calls->activated[$setter] = (bool) $on;
            }
        )->byDefault();
    }
    $package->shouldReceive('selectPackageType')->andReturn(PackageType::PACKAGE_NAME)->byDefault();
    $package->shouldReceive('forcedLimitingOptions')->andReturn($forced)->byDefault();

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getBoolConfig')->andReturn(true)->byDefault();

    $quote = Mockery::mock(Quote::class);
    $quote->shouldReceive('getAllItems')->andReturn([])->byDefault();
    $quote->shouldReceive('getStoreId')->andReturn(1)->byDefault();

    $checkout = newInstanceWithoutConstructor(Checkout::class);
    setPrivateProperty($checkout, 'package', $package);
    setPrivateProperty($checkout, 'config', $config);
    setPrivateProperty($checkout, 'quote', $quote);
    setPrivateProperty($checkout, 'capabilities', $capabilities);

    return ['checkout' => $checkout, 'package' => $package, 'calls' => $calls];
}

it('turns off a package type the account does not have, whatever configuration says', function () {
    // Config says yes to everything; only the contract should be able to say no.
    $mailboxOnly = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
    ]);

    $c = createCheckoutWith(['NL|' => $mailboxOnly]);
    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect($c['calls']->activated['setMailboxActive'])->toBeTrue()
        ->and($c['calls']->activated['setDigitalStampActive'])->toBeFalse()
        ->and($c['calls']->activated['setPackageSmallActive'])->toBeFalse();
});

it('leaves the decision to configuration when capabilities could not be reached', function () {
    $c = createCheckoutWith(['NL|' => CapabilitySet::permissive()]);
    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect($c['calls']->activated['setMailboxActive'])->toBeTrue()
        ->and($c['calls']->activated['setDigitalStampActive'])->toBeTrue()
        ->and($c['calls']->activated['setPackageSmallActive'])->toBeTrue();
});

it('asks only the package-type-agnostic question when the order forces nothing', function () {
    // Seeding only the agnostic shape proves nothing else is consulted: a narrowed lookup would
    // reach the repository, which is not set here, and fatal. That is the ordinary order, and it
    // is what keeps the extra calls off the common path.
    $c = createCheckoutWith(['NL|' => CapabilitySet::fromApiResults([capabilityResult()])]);

    expect($c['checkout']->checkPackageType(Carrier::POSTNL, 'NL'))->toBe(PackageType::PACKAGE_NAME);
});

it('answers per carrier, not once for the store', function () {
    $set = CapabilitySet::fromApiResults([
        capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX', 'DIGITAL_STAMP']]),
        capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE']]),
    ]);

    $postnl = createCheckoutWith(['NL|' => $set]);
    $postnl['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    $dpd = createCheckoutWith(['NL|' => $set]);
    $dpd['checkout']->checkPackageType(Carrier::DPD, 'NL');

    expect($postnl['calls']->activated['setDigitalStampActive'])->toBeTrue()
        ->and($dpd['calls']->activated['setDigitalStampActive'])->toBeFalse();
});

it('rules out a package type that cannot carry an option the order forces on', function () {
    // An 18+ order cannot go as a mailbox when the account has no age check on a mailbox. The
    // agnostic answer still lists mailbox — only the narrowed one knows.
    $c = createCheckoutWith(
        [
            'NL|'        => CapabilitySet::fromApiResults([capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']])]),
            'NL|mailbox' => CapabilitySet::fromApiResults([
                capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresSignature' => []]]),
            ]),
        ],
        'NL',
        [ShipmentOption::AGE_CHECK]
    );

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect($c['calls']->activated['setMailboxActive'])->toBeFalse();
});

it('keeps a package type that can carry the forced option', function () {
    $c = createCheckoutWith(
        [
            'NL|'        => CapabilitySet::fromApiResults([capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']])]),
            'NL|mailbox' => CapabilitySet::fromApiResults([
                capabilityResult(['packageTypes' => ['MAILBOX'], 'options' => ['requiresAgeVerification' => []]]),
            ]),
        ],
        'NL',
        [ShipmentOption::AGE_CHECK]
    );

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect($c['calls']->activated['setMailboxActive'])->toBeTrue();
});

it('answers the forced-option question per carrier', function () {
    // DPD may offer an age check on a mailbox where PostNL does not, so the same 18+ order gets a
    // different answer per carrier. One narrowed set, two carriers, two outcomes.
    $capabilities = [
        'NL|'        => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['PACKAGE', 'MAILBOX']]),
            capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE', 'MAILBOX']]),
        ]),
        'NL|mailbox' => CapabilitySet::fromApiResults([
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

    expect($postnl['calls']->activated['setMailboxActive'])->toBeFalse()
        ->and($dpd['calls']->activated['setMailboxActive'])->toBeTrue();
});

it('restricts nothing when capabilities could not be reached, even with a forced option', function () {
    // Degrade, do not disappear: an unreachable capabilities service must not quietly reprice
    // every 18+ order as a package.
    // Seeding only the agnostic shape also proves no narrowed call is made: a permissive answer
    // means the service is unreachable, and asking it again per package type would only fail again.
    $c = createCheckoutWith(['NL|' => CapabilitySet::permissive()], 'NL', [ShipmentOption::AGE_CHECK]);

    $c['checkout']->checkPackageType(Carrier::POSTNL, 'NL');

    expect($c['calls']->activated['setMailboxActive'])->toBeTrue();
});

/**
 * getDeliveryData() is private and reaches for tax, delivery costs and a dozen config keys, so it
 * is driven with only the collaborators its carrier loop touches. What is asserted is one key.
 */
function deliveryDataAllowsOptionsFor(string $carrierName, array $capabilities, array $forced): bool
{
    [$checkout, $package] = array_values(createCheckoutWith($capabilities, 'NL', $forced));

    $package->shouldReceive('setMailboxSettings')->byDefault();
    $package->shouldReceive('getMaxMailboxWeight')->andReturn(2000)->byDefault();
    $package->shouldReceive('getWeight')->andReturn(100)->byDefault();
    $package->shouldReceive('getPriorityDelivery')->andReturn(false)->byDefault();
    $package->shouldReceive('getAgeCheck')->andReturn(in_array(ShipmentOption::AGE_CHECK, $forced, true))->byDefault();
    $package->shouldReceive('getExcludeParcelLockers')->andReturn(false)->byDefault();
    setPrivateProperty($package, 'deliveryOptionsDisabled', false);

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getBoolConfig')->andReturn(true)->byDefault();
    $config->shouldReceive('getFloatConfig')->andReturn(0.0)->byDefault();
    $config->shouldReceive('getConfigValue')->andReturn(null)->byDefault();
    $config->shouldReceive('getTimeConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getStringConfig')->andReturn('')->byDefault();
    $config->shouldReceive('getIntegerConfig')->andReturn(0)->byDefault();
    setPrivateProperty($checkout, 'config', $config);

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
        'NL|'        => CapabilitySet::fromApiResults([
            capabilityResult(['packageTypes' => ['PACKAGE']]),
            capabilityResult(['carrier' => 'DPD', 'packageTypes' => ['PACKAGE']]),
        ]),
        'NL|mailbox' => CapabilitySet::fromApiResults([
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
