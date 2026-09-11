<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * A forced option is one the order gets whatever the shopper picks: set on a product, or on in the
 * carrier's export settings. Both tiers are always read, because a future entry in
 * ShipmentOption::LIMIT_PACKAGE_TYPE may have only one of them.
 *
 * The product tier is stubbed rather than driven: reading it means an EAV query, which
 * getAttributesProductsOptions() owns and which is not what these cases are about.
 *
 * @param array<string,mixed> $config      config path => value
 * @param array<string,int>   $perProduct  option => value one product carries
 */
function createForcedOptionsRepository(array $config, array $perProduct = []): PackageRepository
{
    /** @var PackageRepository|Mockery\MockInterface $repository */
    $repository = Mockery::mock(PackageRepository::class)
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    $repository->shouldReceive('getConfigValue')
        ->andReturnUsing(static fn(string $path) => $config[$path] ?? null);
    $repository->shouldReceive('getAttributesProductsOptions')
        ->andReturnUsing(static fn($product, string $option) => $perProduct[$option] ?? null);

    return $repository;
}

const FORCED_AGE_CHECK_PATH = 'myparcelnl_magento_postnl_settings/default_options/age_check_active';

it('finds nothing forced when neither the product nor the export setting says so', function () {
    $repository = createForcedOptionsRepository([FORCED_AGE_CHECK_PATH => '0']);

    expect($repository->forcedLimitingOptions([new stdClass()], 'myparcelnl_magento_postnl_settings/'))->toBe([]);
});

it('reads a forced option from the export settings', function () {
    $repository = createForcedOptionsRepository([FORCED_AGE_CHECK_PATH => '1']);

    expect($repository->forcedLimitingOptions([], 'myparcelnl_magento_postnl_settings/'))
        ->toBe([ShipmentOption::AGE_CHECK]);
});

it('reads a forced option from a product, even with the export setting off', function () {
    $repository = createForcedOptionsRepository(
        [FORCED_AGE_CHECK_PATH => '0'],
        [ShipmentOption::AGE_CHECK => 1]
    );

    expect($repository->forcedLimitingOptions([new stdClass()], 'myparcelnl_magento_postnl_settings/'))
        ->toBe([ShipmentOption::AGE_CHECK]);
});

it('treats a config path that does not exist as not forced', function () {
    // A future limiting option may have no export setting for a given carrier. Absent is "no",
    // never an error.
    $repository = createForcedOptionsRepository([]);

    expect($repository->forcedLimitingOptions([], 'myparcelnl_magento_dpd_settings/'))->toBe([]);
});

it('does not read a threshold or a stray string as on', function () {
    // large_format_active ships as the literal 'No' and can hold 'price'. Both cast to true, which
    // is why the read compares against '1'.
    foreach (['No', 'price', '0', ''] as $value) {
        $repository = createForcedOptionsRepository([FORCED_AGE_CHECK_PATH => $value]);

        expect($repository->forcedLimitingOptions([], 'myparcelnl_magento_postnl_settings/'))
            ->toBe([], sprintf('"%s" was read as forced', $value));
    }
});

it('keeps getAgeCheck answering the same question', function () {
    $forced = createForcedOptionsRepository([FORCED_AGE_CHECK_PATH => '1']);
    $unset  = createForcedOptionsRepository([FORCED_AGE_CHECK_PATH => '0']);

    expect($forced->getAgeCheck([], 'myparcelnl_magento_postnl_settings/'))->toBeTrue()
        ->and($unset->getAgeCheck([], 'myparcelnl_magento_postnl_settings/'))->toBeFalse();
});
