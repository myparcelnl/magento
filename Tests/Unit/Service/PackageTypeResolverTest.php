<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\MailboxInternational;
use MyParcelNL\Magento\Service\Weight;

/**
 * A Config answering one case's paths, for group reads and child reads alike.
 *
 * @return Config|Mockery\MockInterface
 */
function packageTypeConfigFor(array $map): Config
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')
           ->andReturnUsing(static fn(string $path, $storeId = null) => $map[$path] ?? null);
    $config->shouldReceive('getGeneralConfig')
           ->andReturnUsing(static fn(string $code = '', $storeId = null) => $map[Config::XML_PATH_GENERAL . $code] ?? null);
    $config->shouldReceive('getBoolConfig')
           ->andReturnUsing(static fn(string $path, string $key): bool => '1' === ($map[$path . $key] ?? null));

    return $config;
}

/**
 * Runs one case of packageTypeCases() against the resolver.
 *
 * The table was written against the class this replaced and ran green on both, which is what makes
 * it a record of behaviour rather than of intent. See its own doc block.
 *
 * The candidates are built here the way Checkout builds them: the capability verdict AND the
 * carrier's own config toggle, with the country choosing which mailbox toggle applies.
 */
function runPackageTypeCaseOnResolver(array $case): string
{
    $map         = packageTypeConfigMap($case);
    $carrierPath = packageTypeCarrierPath($case['carrier']);
    $config      = packageTypeConfigFor($map);

    // The cases name PostNL's flag, so only PostNL answers yes.
    $postnl = Mockery::mock(MailboxInternational::class);
    $postnl->shouldReceive('isEnabledFor')->andReturnUsing(static function (string $carrier) use ($case): bool {
        return 'postnl' === $carrier && (bool) ($case['account']['postnl_mailbox_international'] ?? false);
    });

    $resolver = new PackageTypeResolver(
        $config,
        productAttributesFor(packageTypeProductRows($case['items'])),
        new Weight($config),
        $postnl,
        accountPropositionAt('NL')
    );

    $isActive   = static fn(string $key): bool => '1' === ($map[$carrierPath . $key] ?? null);
    $mailboxKey = CountryCode::CC_NL === $case['country'] ? 'mailbox/active' : 'mailbox/international_active';

    $candidates = PackageTypeCandidates::none();

    if ($case['candidates']['digital_stamp'] && $isActive('digital_stamp/active')) {
        $candidates = $candidates->with(PackageType::DIGITAL_STAMP_NAME);
    }

    if ($case['candidates']['mailbox'] && $isActive($mailboxKey)) {
        $candidates = $candidates->with(PackageType::MAILBOX_NAME);
    }

    if ($case['candidates']['package_small'] && $isActive('package_small/active')) {
        $candidates = $candidates->with(PackageType::PACKAGE_SMALL_NAME);
    }

    return $resolver->resolve(
        packageTypeQuoteItems($case['items']),
        $case['carrier'],
        $case['country'],
        $candidates,
        1
    );
}

it('decides the package type', function (array $case) {
    expect(runPackageTypeCaseOnResolver($case))->toBe($case['expected']);
})->with(packageTypeDataset());

it('answers the same twice, so nothing carried over from the first call', function (array $case) {
    expect(runPackageTypeCaseOnResolver($case))->toBe(runPackageTypeCaseOnResolver($case));
})->with(packageTypeDataset());

it('sums the cart weight without consulting any configuration', function () {
    $resolver = resolverAtHomeIn('NL', Mockery::mock(MailboxInternational::class));

    $items = [quoteItemFor(1, 2.0, 1.5), quoteItemFor(2, 0.0, 99.0), quoteItemFor(3, 1.0, 0.0)];

    expect($resolver->cartWeight($items))->toBe(3.0);
});

function resolverAtHomeIn(string $homeCountry, MailboxInternational $international): PackageTypeResolver
{
    return new PackageTypeResolver(
        packageTypeConfigFor([]),
        productAttributesFor([]),
        new Weight(packageTypeConfigFor([])),
        $international,
        accountPropositionAt($homeCountry)
    );
}

it('allows a mailbox to the account its home country without asking the international flag', function () {
    $international = Mockery::mock(MailboxInternational::class);
    $international->shouldNotReceive('isEnabledFor');

    expect(invokePrivateMethod(resolverAtHomeIn('BE', $international), 'mailboxAllowedTo', ['BE', 'bpost', 1]))->toBeTrue();
});

it('asks the international flag for the netherlands when the account is at home in belgium', function () {
    $international = Mockery::mock(MailboxInternational::class);
    $international->shouldReceive('isEnabledFor')->once()->with('postnl', 1)->andReturn(false);

    expect(invokePrivateMethod(resolverAtHomeIn('BE', $international), 'mailboxAllowedTo', ['NL', 'postnl', 1]))->toBeFalse();
});
