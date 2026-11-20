<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\PostnlMailboxInternational;
use MyParcelNL\Magento\Service\Weight;

/**
 * A resolver over one config map and one set of product rows. The map may be an ArrayObject, so one
 * resolver can answer case after case.
 *
 * @param array<string,mixed>|ArrayAccess $map
 * @param callable(): bool                $mailboxInternational answers PostnlMailboxInternational::isEnabled()
 */
function packageTypeResolverFor(array|ArrayAccess $map, array $rows, callable $mailboxInternational): PackageTypeResolver
{
    $config = configAnswering(static fn(string $path) => $map[$path] ?? null);

    $postnl = Mockery::mock(PostnlMailboxInternational::class);
    $postnl->shouldReceive('isEnabled')->andReturnUsing(static fn(?int $storeId = null): bool => $mailboxInternational());

    return new PackageTypeResolver($config, productAttributesFor($rows), new Weight($config), $postnl);
}

/** Resolve one case of packageTypeCases(), with the candidates built by the resolver itself. */
function resolvePackageTypeCase(PackageTypeResolver $resolver, array $case, int $firstId = 1): string
{
    $carrierPath = packageTypeCarrierPath($case['carrier']);

    return $resolver->resolve(
        packageTypeQuoteItems($case['items'], $firstId),
        $case['carrier'],
        $case['country'],
        $resolver->candidates(packageTypeAllowed($case), $carrierPath, $case['country'], 1),
        1
    );
}

it('decides the package type', function (array $case) {
    $resolver = packageTypeResolverFor(
        packageTypeConfigMap($case),
        packageTypeProductRows($case['items']),
        static fn(): bool => (bool) ($case['account']['postnl_mailbox_international'] ?? false)
    );

    expect(resolvePackageTypeCase($resolver, $case))->toBe($case['expected']);
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

    $resolver = packageTypeResolverFor(
        $map,
        $rows,
        static fn(): bool => (bool) ($account['postnl_mailbox_international'] ?? false)
    );

    foreach ([$cases, array_reverse($cases, true)] as $order) {
        $actual = [];

        foreach ($order as $name => $case) {
            $map->exchangeArray(packageTypeConfigMap($case));
            $account->exchangeArray($case['account'] ?? []);

            $actual[$name] = resolvePackageTypeCase($resolver, $case, $firstId[$name]);
        }

        expect($actual)->toBe(array_map(static fn(array $case): string => $case['expected'], $order));
    }
});

it('finds a cart within the mailbox maximum light enough, and one above it too heavy', function () {
    $resolver = packageTypeResolverFor(
        [Config::XML_PATH_POSTNL_SETTINGS . 'mailbox' => ['active' => '1', 'weight' => '2000']],
        [],
        static fn(): bool => false
    );

    expect($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 2000.0)], Config::XML_PATH_POSTNL_SETTINGS, 1))->toBeTrue()
        ->and($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 2001.0)], Config::XML_PATH_POSTNL_SETTINGS, 1))->toBeFalse();
});

it('finds no cart light enough for a mailbox group that is switched off, not even a weightless one', function () {
    $resolver = packageTypeResolverFor(
        [Config::XML_PATH_POSTNL_SETTINGS . 'mailbox' => ['active' => '0', 'weight' => '2000']],
        [],
        static fn(): bool => false
    );

    expect($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 0.0)], Config::XML_PATH_POSTNL_SETTINGS, 1))->toBeFalse();
});
