<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;

/**
 * A product with no myparcel_fit_in_mailbox row must not divide the mailbox percentage by null.
 *
 * makePackageRepository() answers every attribute with an empty string, which ProductAttributes
 * treats as "no row" — so these are the ordinary cases, not contrived ones.
 */
function repositoryWithNoCarrierSettings()
{
    $repository = makePackageRepository();
    $repository->shouldReceive('getConfigValue')->andReturn(null);
    $repository->shouldReceive('getGeneralConfig')->andReturn(null);

    return $repository;
}

it('treats a missing fit_in_mailbox as -1 rather than dividing by it', function () {
    $repository = repositoryWithNoCarrierSettings();

    expect($repository->selectPackageType([quoteItemFor(1, 2.0, 1.5)], 'postnl'))
        ->toBe(PackageType::PACKAGE_NAME);
});

it('survives a quote item whose catalogue product is gone', function () {
    $repository = repositoryWithNoCarrierSettings();

    expect($repository->selectPackageType([quoteItemFor(null, 1.0, 1.0)], 'postnl'))
        ->toBe(PackageType::PACKAGE_NAME);
});

it('still rules out a mailbox when fit_in_mailbox is explicitly -1', function () {
    $repository = repositoryWithNoCarrierSettings();
    $repository->selectPackageType([quoteItemFor(1, 1.0, 1.0)], 'postnl');

    expect($repository->getMailboxPercentage())->toBe(101.0);
});
