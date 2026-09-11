<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

/**
 * Asserts that the store reaches every config read, not Magento's own scope fallback.
 *
 * @param int|null $storeId null means setStoreId() was never called
 */
function createScopedPackageRepository(?int $storeId, array $configByStore): PackageRepository
{
    /** @var PackageRepository|Mockery\MockInterface $repository */
    $repository = Mockery::mock(PackageRepository::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $repository->shouldReceive('getConfigValue')
        ->andReturnUsing(function (string $path, ?int $askedFor = null) use ($configByStore) {
            return $configByStore[$askedFor][$path] ?? null;
        });

    if (null !== $storeId) {
        $repository->setStoreId($storeId);
    }

    return $repository;
}

$mailboxOn  = ['myparcelnl_magento_postnl_settings/mailbox' => ['active' => '1', 'weight' => '2000']];
$mailboxOff = ['myparcelnl_magento_postnl_settings/mailbox' => ['active' => '0']];

it('reads mailbox settings from the store it was scoped to', function () use ($mailboxOn, $mailboxOff) {
    $repository = createScopedPackageRepository(2, [1 => $mailboxOff, 2 => $mailboxOn]);
    $repository->setMailboxSettings();

    expect($repository->isMailboxActive())->toBeTrue();
});

it('does not read another store settings', function () use ($mailboxOn, $mailboxOff) {
    $repository = createScopedPackageRepository(1, [1 => $mailboxOff, 2 => $mailboxOn]);
    $repository->setMailboxSettings();

    expect($repository->isMailboxActive())->toBeFalse();
});

it('scopes digital stamp settings to the same store', function () {
    $repository = createScopedPackageRepository(2, [
        1 => ['myparcelnl_magento_postnl_settings/digital_stamp' => ['active' => '0']],
        2 => ['myparcelnl_magento_postnl_settings/digital_stamp' => ['active' => '1']],
    ]);
    $repository->setDigitalStampSettings();

    expect($repository->isDigitalStampActive())->toBeTrue();
});

it('scopes package small settings to the same store', function () {
    $repository = createScopedPackageRepository(2, [
        1 => ['myparcelnl_magento_postnl_settings/package_small' => ['active' => '0']],
        2 => ['myparcelnl_magento_postnl_settings/package_small' => ['active' => '1', 'weight' => '2000']],
    ]);
    $repository->setPackageSmallSettings();

    expect($repository->isPackageSmallActive())->toBeTrue();
});

it('passes null when no store was set, preserving ambient resolution', function () use ($mailboxOn) {
    $repository = createScopedPackageRepository(null, [null => $mailboxOn]);
    $repository->setMailboxSettings();

    expect($repository->getStoreId())->toBeNull()
        ->and($repository->isMailboxActive())->toBeTrue();
});
