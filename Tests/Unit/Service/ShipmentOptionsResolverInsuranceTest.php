<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Source\DefaultOptions;

/**
 * The one insurance clamp (DR-19). Both inputs run through it: the posted admin amount and the
 * amount the merchant's configuration resolves to.
 *
 * @param array<string, mixed> $insuranceOption the option value the account reports, in cents
 */
function insuranceResolver(
    $postedAmount,
    ?array $insuranceOption = null,
    ?int $configuredDefault = null,
    string $countryId = 'NL',
    string $packageType = PackageType::PACKAGE_NAME
) {
    $responses = [];

    if (null !== $insuranceOption) {
        $responses[] = new GuzzleResponse(200, [], capabilitiesBody([
            capabilityResult([
                'packageTypes' => [PackageType::toV2Name($packageType) ?? 'PACKAGE'],
                'options'      => capabilityOptions(['insurance' => $insuranceOption]),
            ]),
        ]));
    }

    $repository = null === $insuranceOption ? null : makeCapabilitiesRepository($responses)['repository'];

    $defaultOptions = Mockery::mock(DefaultOptions::class);

    if (null !== $configuredDefault) {
        $defaultOptions->shouldReceive('getDefaultInsurance')->andReturn($configuredDefault);
    }

    return createShipmentOptions(
        $countryId,
        Carrier::POSTNL,
        null === $postedAmount ? [] : ['insurance' => $postedAmount],
        false,
        ['deliveryType' => DeliveryType::STANDARD_NAME, 'packageType' => $packageType],
        $repository,
        $defaultOptions
    );
}

function bounds(int $minCents, int $maxCents): array
{
    return [
        'isRequired' => false,
        'min'        => ['amount' => $minCents, 'currency' => 'EUR'],
        'max'        => ['amount' => $maxCents, 'currency' => 'EUR'],
    ];
}

it('passes an amount inside the contract range through untouched', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(insuranceResolver(137, bounds(0, 500000))->getInsurance())->toBe(137);
});

it('clamps an amount above the contract maximum down, and says so', function () {
    mockLoggerFacade()->shouldReceive('notice')->once()
        ->with(Mockery::pattern('/^Insurance for order 100000001 clamped from 5000 to 2500, .*postnl being 0-2500\.$/'));

    expect(insuranceResolver(5000, bounds(0, 250000))->getInsurance())->toBe(2500);
});

it('clamps an amount below the contract minimum up, never to zero', function () {
    mockLoggerFacade()->shouldReceive('notice')->once();

    expect(insuranceResolver(25, bounds(10000, 250000))->getInsurance())->toBe(100);
});

it('clamps the configured default too, not only a posted amount', function () {
    mockLoggerFacade()->shouldReceive('notice')->once();

    expect(insuranceResolver(null, bounds(0, 250000), 5000)->getInsurance())->toBe(2500);
});

it('leaves zero as zero, because zero means insurance is off', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    expect(insuranceResolver(0, bounds(10000, 250000))->getInsurance())->toBe(0);
});

it('sends the configured amount when the bounds cannot be resolved', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    expect(insuranceResolver(137)->getInsurance())->toBe(137);
});

it('sends the configured amount when the account reports no insurance option', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $resolver = insuranceResolver(137, []);

    expect($resolver->getInsurance())->toBe(137);
});

it('asks with the package type set, so the bound is not a union', function () {
    mockLoggerFacade()->shouldReceive('notice')->never();

    $capabilities = makeCapabilitiesRepository([
        new GuzzleResponse(200, [], capabilitiesBody([capabilityResult()])),
    ]);

    $resolver = createShipmentOptions(
        'NL',
        Carrier::POSTNL,
        ['insurance' => 137],
        false,
        ['deliveryType' => DeliveryType::STANDARD_NAME, 'packageType' => PackageType::MAILBOX_NAME],
        $capabilities['repository']
    );

    $resolver->getInsurance();

    $body = json_decode((string) $capabilities['history'][0]['request']->getBody(), true);

    expect($body['packageType'])->toBe('MAILBOX')
        ->and($body['carrier'])->toBe('POSTNL')
        ->and($body['recipient']['countryCode'])->toBe('NL');
});

it('does not ask at all for a package type it cannot name on the wire', function () {
    mockLoggerFacade()->shouldReceive('notice')->zeroOrMoreTimes();

    $capabilities = makeCapabilitiesRepository([
        new GuzzleResponse(200, [], capabilitiesBody([capabilityResult()])),
    ]);

    $resolver = createShipmentOptions(
        'NL',
        Carrier::POSTNL,
        ['insurance' => 9000],
        false,
        ['deliveryType' => DeliveryType::STANDARD_NAME, 'packageType' => 'pallet_xl'],
        $capabilities['repository']
    );

    expect($resolver->getInsurance())->toBe(9000)
        ->and($capabilities['history'])->toBeEmpty();
});
