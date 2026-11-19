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

it('lets the weight decide when a product has no fit_in_mailbox row', function () {
    $repository = repositoryWithNoCarrierSettings();
    $repository->setMaxMailboxWeight(3.0);
    $repository->selectPackageType([quoteItemFor(1, 2.0, 1.5)], 'postnl');

    // 3.0 / 1.5 = 2 items fit, and the order has 2.
    expect($repository->getMailboxPercentage())->toBe(100.0);
});

it('survives a quote item whose catalogue product is gone', function () {
    $repository = repositoryWithNoCarrierSettings();

    expect($repository->selectPackageType([quoteItemFor(null, 1.0, 1.0)], 'postnl'))
        ->toBe(PackageType::PACKAGE_NAME);
});

it('still rules out a mailbox when fit_in_mailbox is explicitly -1', function () {
    $repository = repositoryWithNoCarrierSettings();
    mockAttributeValueLookup('-1');
    $repository->selectPackageType([quoteItemFor(1, 1.0, 1.0)], 'postnl');

    expect($repository->getMailboxPercentage())->toBe(101.0);
});
