<?php

declare(strict_types=1);

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Config;

// capabilityResult() lives in Tests/Helpers/CapabilitiesFixtures.php.

/**
 * The constructor is skipped: it reads the checkout session. checkPackageType() needs only the
 * quote, the config, the package repository and the memoised capabilities.
 *
 * $capabilities is keyed the way Checkout keys it: "<country>|<packageType>", with an empty package
 * type for the shape-agnostic lookup.
 *
 * @return array{checkout: Checkout, package: PackageRepository, calls: object}
 */
function createCheckoutWith(array $capabilities, string $country = 'NL'): array
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

it('asks the package-type-agnostic question, because it is what decides the package type', function () {
    // Seeding only the agnostic shape proves nothing else is consulted: a narrowed lookup would
    // reach the repository, which is not set here, and fatal.
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
