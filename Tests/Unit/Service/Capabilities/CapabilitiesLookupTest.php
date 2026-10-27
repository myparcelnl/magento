<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Service\Capabilities\CapabilitiesProvider;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

/** A lookup whose provider records every request it is asked. */
function recordingCapabilitiesLookup(): array
{
    $requests = new ArrayObject();
    $provider = Mockery::mock(CapabilitiesProvider::class);
    $provider->shouldReceive('forApiKey')->andReturnUsing(
        static function (string $apiKey, CapabilitiesRequest $request) use ($requests): CapabilitySet {
            $requests[] = $request;

            return CapabilitySet::fromApiResults([]);
        }
    );

    return [capabilitiesLookupOver($provider), $requests];
}

it('sends the delivery type as its v2 name', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forRequest(1, 'NL', 'package', 'postnl', DeliveryType::MORNING_NAME);

    expect($requests[0]->getDeliveryType())->toBe(DeliveryType::toV2Name(DeliveryType::MORNING_NAME));
});

it('sends each ticked option as an enabled filter', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forRequest(1, 'NL', 'package', 'postnl', null, ['age_check', 'signature']);

    expect($requests[0]->getOptions())->toBe(['age_check' => null, 'signature' => null]);
});

it('asks once for the same options in another order', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forRequest(1, 'NL', 'package', 'postnl', null, ['signature', 'age_check']);
    $lookup->forRequest(1, 'NL', 'package', 'postnl', null, ['age_check', 'signature']);

    expect($requests)->toHaveCount(1);
});

it('asks again for another delivery type', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forRequest(1, 'NL', 'package', 'postnl', DeliveryType::STANDARD_NAME);
    $lookup->forRequest(1, 'NL', 'package', 'postnl', DeliveryType::MORNING_NAME);

    expect($requests)->toHaveCount(2);
});

it('answers permissive for a delivery type it cannot map', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    expect($lookup->forRequest(1, 'NL', 'package', 'postnl', 'nonsense')->isPermissive())->toBeTrue()
        ->and($requests)->toHaveCount(0);
});

it('sends the delivery type and options for an api key too', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forApiKeyRequest('key', 'NL', 'package', 'postnl', DeliveryType::EVENING_NAME, ['signature']);

    expect($requests[0]->getDeliveryType())->toBe(DeliveryType::toV2Name(DeliveryType::EVENING_NAME))
        ->and($requests[0]->getOptions())->toBe(['signature' => null]);
});

it('fails open for a store with no api key rather than stopping the page', function () {
    $logger = mockLoggerFacade();
    $logger->shouldReceive('warning')->once()->with(Mockery::pattern('/No MyParcel API key for store 7/'));

    $r      = makeCapabilitiesProvider();
    $lookup = capabilitiesLookupOver($r['provider'], '');

    expect($lookup->forRequest(7, 'NL')->isPermissive())->toBeTrue()
        ->and($lookup->forRequest(7, 'NL', 'package')->isPermissive())->toBeTrue()
        ->and($lookup->answeredPermissively())->toBeTrue()
        ->and($r['history'])->toHaveCount(0);
});

it('asks once for two stores that share one api key', function () {
    [$lookup, $requests] = recordingCapabilitiesLookup();

    $lookup->forRequest(1, 'NL');
    $lookup->forRequest(2, 'NL');

    expect($requests)->toHaveCount(1);
});
