<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * A resolver for a PostNL NL package, with requires and excludes from $capabilityOptions.
 *
 * @param array<string,int> $sources         option => OptionSource tier, for what DefaultOptions switches on
 * @param array|null        $capabilityOptions null for a lookup that fails, which falls open
 */
function dependencyResolver(
    ?array $capabilityOptions,
    array  $posted = [],
    array  $sources = [],
    int    $requiredInsurance = 0
) {
    $repository = null;

    if (null !== $capabilityOptions) {
        $repository = makeCapabilitiesRepository([
            new GuzzleResponse(200, [], capabilitiesBody([
                capabilityResult(['packageTypes' => ['PACKAGE'], 'options' => $capabilityOptions]),
            ])),
        ])['repository'];
    }

    $defaultOptions = Mockery::mock(DefaultOptions::class);
    $defaultOptions->shouldReceive('hasOptionSet')->andReturnUsing(static fn(string $option): bool => isset($sources[$option]));
    $defaultOptions->shouldReceive('sourceOf')->andReturnUsing(static fn(string $option): ?int => $sources[$option] ?? null);
    $defaultOptions->shouldReceive('getDefaultInsurance')->andReturn(0);
    $defaultOptions->shouldReceive('getRequiredInsurance')->andReturn($requiredInsurance);

    return createShipmentOptions(
        'NL',
        'postnl',
        $posted,
        false,
        ['deliveryType' => DeliveryType::STANDARD_NAME, 'packageType' => PackageType::PACKAGE_NAME],
        $repository,
        $defaultOptions
    );
}

/** Collects every notice, so a test can assert on the one it cares about. */
function captureNotices(): ArrayObject
{
    $notices = new ArrayObject();

    mockLoggerFacade()->shouldReceive('notice')->andReturnUsing(static function (string $message) use ($notices): void {
        $notices->append($message);
    });

    return $notices;
}
