<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\MailboxInternational;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\Weight;

/**
 * A resolver over one config map and one set of product rows. The map may be an ArrayObject, so one
 * resolver can answer case after case.
 *
 * @param array<string,mixed>|ArrayAccess $map
 * @param callable(): bool                $mailboxInternational answers MailboxInternational::isEnabledFor() for PostNL
 */
function packageTypeResolverFor(
    array|ArrayAccess $map,
    array             $rows,
    callable          $mailboxInternational,
    string            $homeCountry = 'NL'
): PackageTypeResolver
{
    $config = configAnswering(static fn(string $path) => $map[$path] ?? null);

    // The cases name PostNL's flag, so only PostNL answers yes.
    $mailbox = Mockery::mock(MailboxInternational::class);
    $mailbox->shouldReceive('isEnabledFor')->andReturnUsing(
        static fn(string $carrier, ?int $storeId = null): bool => 'postnl' === $carrier && $mailboxInternational()
    );

    return new PackageTypeResolver(
        $config,
        productAttributesFor($rows),
        new Weight($config),
        $mailbox,
        accountPropositionAt($homeCountry)
    );
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
        [Config::carrierPath('postnl') . 'mailbox' => ['active' => '1', 'weight' => '2000']],
        [],
        static fn(): bool => false
    );

    expect($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 2000.0)], Config::carrierPath('postnl'), 1))->toBeTrue()
        ->and($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 2001.0)], Config::carrierPath('postnl'), 1))->toBeFalse();
});

it('finds no cart light enough for a mailbox group that is switched off, not even a weightless one', function () {
    $resolver = packageTypeResolverFor(
        [Config::carrierPath('postnl') . 'mailbox' => ['active' => '0', 'weight' => '2000']],
        [],
        static fn(): bool => false
    );

    expect($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 0.0)], Config::carrierPath('postnl'), 1))->toBeFalse();
});

function resolverAtHomeIn(string $homeCountry, MailboxInternational $international): PackageTypeResolver
{
    $config = configAnswering(static fn() => null);

    return new PackageTypeResolver(
        $config,
        productAttributesFor([]),
        new Weight($config),
        $international,
        accountPropositionAt($homeCountry)
    );
}

it('reads the home mailbox toggle for the account its home country, not for the netherlands', function () {
    $resolver = packageTypeResolverFor(
        [
            Config::carrierPath('postnl') . 'mailbox/active'               => '1',
            Config::carrierPath('postnl') . 'mailbox/international_active' => '0',
        ],
        [],
        static fn(): bool => true,
        'BE'
    );
    $allowed  = [PackageType::MAILBOX_NAME => true];

    expect($resolver->candidates($allowed, Config::carrierPath('postnl'), 'BE', 1)->has(PackageType::MAILBOX_NAME))->toBeTrue()
        ->and($resolver->candidates($allowed, Config::carrierPath('postnl'), 'NL', 1)->has(PackageType::MAILBOX_NAME))->toBeFalse();
});

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
