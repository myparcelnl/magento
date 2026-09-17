<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\PackageTypeResolver;
use MyParcelNL\Magento\Service\PostnlMailboxInternational;
use MyParcelNL\Magento\Service\Weight;

/**
 * Asserts that the store reaches every config read, rather than Magento's own scope fallback.
 *
 * Its predecessor had to be told the store once, up front, and every later read depended on that
 * having happened first. Here the store is an argument, so there is no order left to get wrong.
 */
function createScopedResolver(array $configByStore): PackageTypeResolver
{
    $config = Mockery::mock(Config::class);
    $config->shouldReceive('getConfigValue')
           ->andReturnUsing(static fn(string $path, $storeId = null) => $configByStore[$storeId][$path] ?? null);
    $config->shouldReceive('getGeneralConfig')
           ->andReturnUsing(static fn(string $code = '', $storeId = null) => $configByStore[$storeId][Config::XML_PATH_GENERAL . $code] ?? null);

    return new PackageTypeResolver(
        $config,
        productAttributesFor([]),
        new Weight($config),
        Mockery::mock(PostnlMailboxInternational::class)
    );
}

const SCOPED_POSTNL_PATH = 'myparcelnl_magento_postnl_settings/';

$mailboxOn  = [SCOPED_POSTNL_PATH . 'mailbox' => ['active' => '1', 'weight' => '1500']];
$mailboxOff = [SCOPED_POSTNL_PATH . 'mailbox' => ['active' => '0']];

it('reads mailbox settings from the store it was asked about', function () use ($mailboxOn, $mailboxOff) {
    $resolver = createScopedResolver([1 => $mailboxOff, 2 => $mailboxOn]);

    expect($resolver->maxMailboxWeight(SCOPED_POSTNL_PATH, 2))->toBe(1500.0);
});

it('does not read another store settings', function () use ($mailboxOn, $mailboxOff) {
    $resolver = createScopedResolver([1 => $mailboxOff, 2 => $mailboxOn]);

    expect($resolver->maxMailboxWeight(SCOPED_POSTNL_PATH, 1))->toBe(0.0);
});

it('scopes digital stamp settings to the same store', function () {
    $resolver = createScopedResolver([
        1 => [SCOPED_POSTNL_PATH . 'digital_stamp' => ['active' => '0']],
        2 => [SCOPED_POSTNL_PATH . 'digital_stamp' => ['active' => '1']],
    ]);

    expect($resolver->maxDigitalStampWeight(SCOPED_POSTNL_PATH, 2))->toBe(2000.0)
        ->and($resolver->maxDigitalStampWeight(SCOPED_POSTNL_PATH, 1))->toBe(0.0);
});

it('scopes package small settings to the same store', function () {
    $resolver = createScopedResolver([
        1 => [SCOPED_POSTNL_PATH . 'package_small' => ['active' => '0']],
        2 => [SCOPED_POSTNL_PATH . 'package_small' => ['active' => '1', 'weight' => '800']],
    ]);

    expect($resolver->maxPackageSmallWeight(SCOPED_POSTNL_PATH, 2))->toBe(800.0)
        ->and($resolver->maxPackageSmallWeight(SCOPED_POSTNL_PATH, 1))->toBe(0.0);
});

it('passes null through when no store was named, preserving ambient resolution', function () use ($mailboxOn) {
    $resolver = createScopedResolver([null => $mailboxOn]);

    expect($resolver->maxMailboxWeight(SCOPED_POSTNL_PATH, null))->toBe(1500.0);
});

it('does not carry one store maximum weight into the next store', function () use ($mailboxOn, $mailboxOff) {
    // The predecessor kept the weight on the instance and its loader returned early without
    // clearing it, so a carrier or store with no mailbox group inherited the previous one.
    $resolver = createScopedResolver([1 => $mailboxOff, 2 => $mailboxOn]);

    expect($resolver->maxMailboxWeight(SCOPED_POSTNL_PATH, 2))->toBe(1500.0)
        ->and($resolver->maxMailboxWeight(SCOPED_POSTNL_PATH, 1))->toBe(0.0);
});
