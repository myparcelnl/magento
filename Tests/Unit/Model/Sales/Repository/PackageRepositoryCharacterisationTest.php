<?php

declare(strict_types=1);

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;

const CHARACTERISATION_API_KEY = 'characterisation-key';

/**
 * Runs one case of packageTypeCases() the way Checkout::checkPackageType() runs it today: the three
 * settings loaders first, then the capability overrides, then selectPackageType().
 *
 * This is the BEFORE half of the pair. It is deleted together with PackageRepository; the table it
 * reads outlives it and proves the resolver answers the same.
 */
function runPackageTypeCaseOnRepository(array $case): string
{
    $map        = packageTypeConfigMap($case);
    $collection = productCollectionFor(packageTypeProductRows($case['items']));

    $objectManager = Mockery::mock(ObjectManagerInterface::class);
    $objectManager->shouldReceive('create')->with(ProductCollection::class)->andReturn($collection);
    $objectManager->shouldReceive('get')->with(Fingerprint::class)->andReturn(new Fingerprint());
    $objectManager->shouldReceive('get')->with(LoggerInterface::class)
                  ->andReturn(Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing());
    $objectManager->shouldReceive('get')->with(ScopeConfigInterface::class)->andReturn(
        mockScopeConfig([
            settingsPathFor(CHARACTERISATION_API_KEY) => accountSettingsRow([], [
                'account' => [
                    'id'               => 7,
                    'platform_id'      => 1,
                    'general_settings' => $case['account'],
                ],
            ]),
        ])
    );
    ObjectManager::setInstance($objectManager);

    /** @var PackageRepository|Mockery\MockInterface $repository */
    $repository = Mockery::mock(PackageRepository::class)->makePartial()->shouldAllowMockingProtectedMethods();
    $repository->shouldReceive('getConfigValue')->andReturnUsing(
        static function (string $path) use ($map) {
            if (str_ends_with($path, 'general/api/key')) {
                return CHARACTERISATION_API_KEY;
            }

            return $map[$path] ?? null;
        }
    );

    $carrierPath = packageTypeCarrierPath($case['carrier']);
    $country     = $case['country'];
    $candidates  = $case['candidates'];
    $isActive    = static fn(string $key): bool => '1' === ($map[$carrierPath . $key] ?? null);

    $repository->setMailboxSettings($carrierPath);
    $repository->setDigitalStampSettings($carrierPath);
    $repository->setPackageSmallSettings($carrierPath);

    if ($candidates['mailbox']) {
        $repository->setMailboxActive(
            $isActive(CountryCode::CC_NL === $country ? 'mailbox/active' : 'mailbox/international_active')
        );
    } else {
        $repository->setMailboxActive(false);
    }

    $repository->setCurrentCountry($country);
    $repository->setDigitalStampActive($candidates['digital_stamp'] && $isActive('digital_stamp/active'));
    $repository->setPackageSmallActive($candidates['package_small'] && $isActive('package_small/active'));

    return $repository->selectPackageType(packageTypeQuoteItems($case['items']), $case['carrier']);
}

it('decides the package type', function (array $case) {
    expect(runPackageTypeCaseOnRepository($case))->toBe($case['expected']);
})->with(packageTypeDataset());

it('never answers a package type outside the four the module knows', function (array $case) {
    expect(runPackageTypeCaseOnRepository($case))->toBeIn([
        PackageType::DIGITAL_STAMP_NAME,
        PackageType::MAILBOX_NAME,
        PackageType::PACKAGE_SMALL_NAME,
        PackageType::PACKAGE_NAME,
    ]);
})->with(packageTypeDataset());
