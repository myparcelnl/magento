<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

/**
 * @param bool  $generalActive value of <carrierPath>mailbox/priority_delivery_active
 * @param array $productFlags  one entry per cart product: 1 = attribute on, 0 = off, null = not set
 */
function createPackageRepository(bool $generalActive, array $productFlags): PackageRepository
{
    /** @var PackageRepository|Mockery\MockInterface $repository */
    $repository = Mockery::mock(PackageRepository::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $repository->shouldReceive('getConfigValue')
        ->with('myparcelnl_magento_postnl_settings/mailbox/priority_delivery_active')
        ->andReturn($generalActive ? '1' : '0');

    $repository->shouldReceive('getProductPriorityDelivery')
        ->andReturnValues($productFlags ?: [null]);

    return $repository;
}

// AC2: general setting on -> always true, regardless of products
it('returns true when the general priority setting is enabled', function () {
    $repository = createPackageRepository(true, [null, null]);

    expect($repository->getPriorityDelivery(
        ['productA', 'productB'],
        'myparcelnl_magento_postnl_settings/'
    ))->toBeTrue();
});

it('returns true when the general setting is enabled and the cart is empty', function () {
    $repository = createPackageRepository(true, []);

    expect($repository->getPriorityDelivery([], 'myparcelnl_magento_postnl_settings/'))->toBeTrue();
});

// AC3: general setting off, at least one product with the attribute -> true
it('returns true when the general setting is off but one product has priority enabled', function () {
    $repository = createPackageRepository(false, [null, 1]);

    expect($repository->getPriorityDelivery(
        ['productA', 'productB'],
        'myparcelnl_magento_postnl_settings/'
    ))->toBeTrue();
});

// AC4: general setting off, no product with the attribute -> false
it('returns false when the general setting is off and no product has priority enabled', function () {
    $repository = createPackageRepository(false, [null, 0]);

    expect($repository->getPriorityDelivery(
        ['productA', 'productB'],
        'myparcelnl_magento_postnl_settings/'
    ))->toBeFalse();
});

it('returns false when the general setting is off and the cart is empty', function () {
    $repository = createPackageRepository(false, []);

    expect($repository->getPriorityDelivery([], 'myparcelnl_magento_postnl_settings/'))->toBeFalse();
});
