<?php

declare(strict_types=1);

use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions\PickupLocations;
use MyParcelNL\Magento\Service\ShipmentOptions\PickupLocations as PickupLocationsService;
use MyParcelNL\Magento\Tests\Stub\WithTestRequest;

final class PickupLocationsWithRequest extends PickupLocations
{
    use WithTestRequest;
}

function pickupLocationsAnswer(PickupLocationsService $service, int $orderCount = 1): array
{
    return shipmentOptionsAnswer(PickupLocationsWithRequest::class, ['carrier' => 'postnl'], ['pickupLocations' => $service], $orderCount);
}

it('answers the locations near the shipping address of the order', function () {
    $service = Mockery::mock(PickupLocationsService::class);
    $service->shouldReceive('near')->with(Mockery::any(), 'postnl')->andReturn([['location_code' => '176227']]);

    expect(pickupLocationsAnswer($service))->toBe(['locations' => [['location_code' => '176227']]]);
});

it('answers an error the merchant can read when the locations cannot be loaded', function () {
    mockLoggerFacade()->shouldReceive('notice');
    $service = Mockery::mock(PickupLocationsService::class);
    $service->shouldReceive('near')->andThrow(new RuntimeException('the pickup locations request was refused'));

    pickupLocationsAnswer($service);
})->throws(LocalizedException::class, 'MyParcel could not load the pickup locations');

it('refuses more than one order', function () {
    pickupLocationsAnswer(Mockery::mock(PickupLocationsService::class), 2);
})->throws(LocalizedException::class);
