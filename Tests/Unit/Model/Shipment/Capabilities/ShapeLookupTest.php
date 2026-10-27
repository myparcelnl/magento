<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Repository;
use MyParcelNL\Magento\Model\Shipment\Capabilities\ShapeLookup;
use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Sdk\Model\Capabilities\CapabilitiesRequest;

// recordingShapeLookup() lives in Tests/Helpers/CapabilitiesFixtures.php.

it('sends the delivery type as its v2 name', function () {
    [$lookup, $requests] = recordingShapeLookup();

    $lookup->forShape(1, 'NL', 'package', 'postnl', DeliveryType::MORNING_NAME);

    expect($requests[0]->getDeliveryType())->toBe(DeliveryType::toV2Name(DeliveryType::MORNING_NAME));
});

it('sends each ticked option as an enabled filter', function () {
    [$lookup, $requests] = recordingShapeLookup();

    $lookup->forShape(1, 'NL', 'package', 'postnl', null, ['age_check', 'signature']);

    expect($requests[0]->getOptions())->toBe(['age_check' => null, 'signature' => null]);
});

it('asks once for the same options in another order', function () {
    [$lookup, $requests] = recordingShapeLookup();

    $lookup->forShape(1, 'NL', 'package', 'postnl', null, ['signature', 'age_check']);
    $lookup->forShape(1, 'NL', 'package', 'postnl', null, ['age_check', 'signature']);

    expect($requests)->toHaveCount(1);
});

it('asks again for another delivery type', function () {
    [$lookup, $requests] = recordingShapeLookup();

    $lookup->forShape(1, 'NL', 'package', 'postnl', DeliveryType::STANDARD_NAME);
    $lookup->forShape(1, 'NL', 'package', 'postnl', DeliveryType::MORNING_NAME);

    expect($requests)->toHaveCount(2);
});

it('answers permissive for a delivery type it cannot map', function () {
    [$lookup, $requests] = recordingShapeLookup();

    expect($lookup->forShape(1, 'NL', 'package', 'postnl', 'nonsense')->isPermissive())->toBeTrue()
        ->and($requests)->toHaveCount(0);
});

it('sends the delivery type and options for an api key too', function () {
    $requests   = new ArrayObject();
    $repository = Mockery::mock(Repository::class);
    $repository->shouldReceive('forApiKey')->andReturnUsing(
        static function (string $apiKey, CapabilitiesRequest $request) use ($requests): CapabilitySet {
            $requests[] = $request;

            return CapabilitySet::fromApiResults([]);
        }
    );

    (new ShapeLookup($repository))->forApiKeyShape('key', 'NL', 'package', 'postnl', DeliveryType::EVENING_NAME, ['signature']);

    expect($requests[0]->getDeliveryType())->toBe(DeliveryType::toV2Name(DeliveryType::EVENING_NAME))
        ->and($requests[0]->getOptions())->toBe(['signature' => null]);
});
