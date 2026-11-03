<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * The constructor is skipped throughout: it resolves Config from the live ObjectManager, while
 * these methods read only $chosenOptions.
 */
function createDefaultOptionsWithChosen(array $chosenOptions): DefaultOptions
{
    $options = newInstanceWithoutConstructor(DefaultOptions::class);
    setPrivateProperty($options, 'chosenOptions', $chosenOptions);

    return $options;
}

it('resolves a known package type name without logging', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    $options = createDefaultOptionsWithChosen(['packageType' => 'digital_stamp']);

    expect($options->getPackageType())->toBe(PackageType::DIGITAL_STAMP);
});

it('resolves the types beta.15 never knew', function () {
    mockLoggerFacade();

    expect(createDefaultOptionsWithChosen(['packageType' => 'pallet'])->getPackageType())
        ->toBe(PackageType::PALLET)
        ->and(createDefaultOptionsWithChosen(['packageType' => 'envelope'])->getPackageType())
        ->toBe(PackageType::ENVELOPE);
});

it('falls back to package and logs when the stored name is unknown', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message): bool {
            return false !== strpos($message, 'pallet_xl')
                && false !== strpos($message, PackageType::DEFAULT_NAME);
        });

    $options = createDefaultOptionsWithChosen(['packageType' => 'pallet_xl']);

    expect($options->getPackageType())->toBe(PackageType::PACKAGE);
});

it('defaults silently when no package type was stored at all', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createDefaultOptionsWithChosen([])->getPackageType())->toBe(PackageType::PACKAGE);
});

it('returns an unrecognised package type name verbatim, without logging', function () {
    $logger = mockLoggerFacade();
    $logger->shouldNotReceive('warning');

    expect(createDefaultOptionsWithChosen(['packageType' => 'pallet_xl'])->getPackageTypeName())
        ->toBe('pallet_xl')
        ->not->toBe(PackageType::PACKAGE_NAME);
});

it('returns a recognised package type name verbatim too', function () {
    mockLoggerFacade();

    expect(createDefaultOptionsWithChosen(['packageType' => 'digital_stamp'])->getPackageTypeName())
        ->toBe('digital_stamp');
});

it('returns null when the order stores no package type', function () {
    mockLoggerFacade();

    expect(createDefaultOptionsWithChosen([])->getPackageTypeName())->toBeNull();
});
