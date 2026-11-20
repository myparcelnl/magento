<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\PostnlMailboxInternational;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * A Config answering one case's paths, for group reads and child reads alike.
 *
 * @param array<string,mixed>|ArrayAccess $map an ArrayObject lets one Config answer case after case
 * @return Config|Mockery\MockInterface
 */
function packageTypeConfigFor(array|ArrayAccess $map): Config
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
 */
function runPackageTypeCaseOnResolver(array $case): string
{
    $map    = packageTypeConfigMap($case);
    $config = packageTypeConfigFor($map);

    $postnl = Mockery::mock(PostnlMailboxInternational::class);
    $postnl->shouldReceive('isEnabled')
           ->andReturn((bool) ($case['account']['postnl_mailbox_international'] ?? false));

    $resolver = new PackageTypeResolver(
        $config,
        productAttributesFor(packageTypeProductRows($case['items'])),
        new Weight($config),
        $postnl
    );

    return $resolver->resolve(
        packageTypeQuoteItems($case['items']),
        $case['carrier'],
        $case['country'],
        packageTypeCandidatesFor($case, $map),
        1
    );
}

/**
 * The candidates the way Checkout builds them: the capability verdict AND the carrier's own config
 * toggle, with the country choosing which mailbox toggle applies.
 *
 * @param array<string,mixed>|ArrayAccess $map
 */
function packageTypeCandidatesFor(array $case, array|ArrayAccess $map): PackageTypeCandidates
{
    $carrierPath = packageTypeCarrierPath($case['carrier']);
    $isActive    = static fn(string $key): bool => '1' === ($map[$carrierPath . $key] ?? null);
    $mailboxKey = CountryCodes::CC_NL === $case['country'] ? 'mailbox/active' : 'mailbox/international_active';

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

    return $candidates;
}

it('decides the package type', function (array $case) {
    expect(runPackageTypeCaseOnResolver($case))->toBe($case['expected']);
})->with(packageTypeDataset());

it('decides every case on one shared resolver, in both orders, so no call leaves state behind', function () {
    $cases   = packageTypeCases();
    $map     = new ArrayObject();
    $account = new ArrayObject();
    $rows    = [];
    $firstId = [];
    $nextId  = 1;

    foreach ($cases as $name => $case) {
        $firstId[$name] = $nextId;
        $rows          += packageTypeProductRows($case['items'], $nextId);
        $nextId        += count($case['items']);
    }

    $postnl = Mockery::mock(PostnlMailboxInternational::class);
    $postnl->shouldReceive('isEnabled')
           ->andReturnUsing(static fn(?int $storeId = null): bool => (bool) ($account['postnl_mailbox_international'] ?? false));

    $config   = packageTypeConfigFor($map);
    $resolver = new PackageTypeResolver($config, productAttributesFor($rows), new Weight($config), $postnl);

    foreach ([$cases, array_reverse($cases, true)] as $order) {
        $actual = [];

        foreach ($order as $name => $case) {
            $map->exchangeArray(packageTypeConfigMap($case));
            $account->exchangeArray($case['account'] ?? []);

            $actual[$name] = $resolver->resolve(
                packageTypeQuoteItems($case['items'], $firstId[$name]),
                $case['carrier'],
                $case['country'],
                packageTypeCandidatesFor($case, $map),
                1
            );
        }

        expect($actual)->toBe(array_map(static fn(array $case): string => $case['expected'], $order));
    }
});

it('sums the cart weight without consulting any configuration', function () {
    $resolver = new PackageTypeResolver(
        packageTypeConfigFor([]),
        productAttributesFor([]),
        new Weight(packageTypeConfigFor([])),
        Mockery::mock(PostnlMailboxInternational::class)
    );

    $items = [quoteItemFor(1, 2.0, 1.5), quoteItemFor(2, 0.0, 99.0), quoteItemFor(3, 1.0, 0.0)];

    expect($resolver->cartWeight($items))->toBe(3.0);
});
