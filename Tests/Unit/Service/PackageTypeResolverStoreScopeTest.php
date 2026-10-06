<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\MailboxInternational;
use MyParcelNL\Magento\Service\Weight;

/**
 * A resolver whose config answers per store, so a read with the wrong store id finds other groups.
 *
 * @param array<int|string, array<string, array<string, string>>> $groupsByStore store id => PostNL group => children
 */
function createScopedResolver(array $groupsByStore): PackageTypeResolver
{
    $mapByStore = array_map(
        static fn(array $groups): array => packageTypeConfigMap(['carrier' => 'postnl', 'groups' => $groups, 'weightUnit' => 'gram']),
        $groupsByStore
    );
    $config = configAnswering(static fn(string $path, ?int $storeId) => $mapByStore[$storeId ?? ''][$path] ?? null);

    return new PackageTypeResolver(
        $config,
        productAttributesFor([]),
        new Weight($config),
        Mockery::mock(MailboxInternational::class),
        accountPropositionAt('NL')
    );
}

$everything = [
    PackageType::DIGITAL_STAMP_NAME => true,
    PackageType::MAILBOX_NAME       => true,
    PackageType::PACKAGE_SMALL_NAME => true,
];

it('reads the carrier toggles of the store it is asked about', function () use ($everything) {
    $resolver = createScopedResolver([
        1 => ['digital_stamp' => ['active' => '0'], 'package_small' => ['active' => '0']],
        2 => ['digital_stamp' => ['active' => '1'], 'package_small' => ['active' => '1']],
    ]);

    $inStore2 = $resolver->candidates($everything, Config::carrierPath('postnl'), 'NL', 2);
    $inStore1 = $resolver->candidates($everything, Config::carrierPath('postnl'), 'NL', 1);

    expect($inStore2->has(PackageType::DIGITAL_STAMP_NAME))->toBeTrue()
        ->and($inStore2->has(PackageType::PACKAGE_SMALL_NAME))->toBeTrue()
        ->and($inStore1->has(PackageType::DIGITAL_STAMP_NAME))->toBeFalse()
        ->and($inStore1->has(PackageType::PACKAGE_SMALL_NAME))->toBeFalse();
});

it('reads the mailbox group per store, and keeps no maximum from the store before', function () {
    $resolver = createScopedResolver([
        1 => ['mailbox' => ['active' => '1', 'weight' => '500']],
        2 => ['mailbox' => ['active' => '1', 'weight' => '1500']],
    ]);
    $cart     = [quoteItemFor(1, 1.0, 1000.0)];

    expect($resolver->fitsMailboxWeight($cart, Config::carrierPath('postnl'), 2))->toBeTrue()
        ->and($resolver->fitsMailboxWeight($cart, Config::carrierPath('postnl'), 1))->toBeFalse()
        ->and($resolver->fitsMailboxWeight($cart, Config::carrierPath('postnl'), 2))->toBeTrue();
});

it('reads the package small maximum of the store it is asked about', function () {
    $resolver   = createScopedResolver([
        1 => ['package_small' => ['active' => '1', 'weight' => '500']],
        2 => ['package_small' => ['active' => '1', 'weight' => '800']],
    ]);
    $candidates = PackageTypeCandidates::none()->with(PackageType::PACKAGE_SMALL_NAME);
    $cart       = [quoteItemFor(1, 1.0, 700.0)];

    expect($resolver->resolve($cart, 'postnl', 'NL', $candidates, 2))->toBe(PackageType::PACKAGE_SMALL_NAME)
        ->and($resolver->resolve($cart, 'postnl', 'NL', $candidates, 1))->toBe(PackageType::PACKAGE_NAME);
});

it('passes null through when no store was named, so Magento resolves the store', function () {
    $resolver = createScopedResolver(['' => ['mailbox' => ['active' => '1', 'weight' => '1500']]]);

    expect($resolver->fitsMailboxWeight([quoteItemFor(1, 1.0, 1000.0)], Config::carrierPath('postnl'), null))->toBeTrue();
});
