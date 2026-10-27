<?php

declare(strict_types=1);

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions\PickupLocations;
use MyParcelNL\Magento\Service\ShipmentOptions\PickupLocations as PickupLocationsService;

final class PickupLocationsWithRequest extends PickupLocations
{
    /** @var RequestInterface|null Declared here: the test stub of Action has no $_request. */
    protected $_request;

    public function getRequest()
    {
        return $this->_request;
    }
}

/** The PickupLocations controller over a service double, for the given number of orders. */
function pickupLocationsAnswer(PickupLocationsService $service, int $orderCount = 1): array
{
    $request = Mockery::mock(RequestInterface::class);
    $request->shouldReceive('getParam')->andReturnUsing(static fn(string $name, $default = null) => ['carrier' => 'postnl'][$name] ?? $default);

    $controller = newInstanceWithoutConstructor(PickupLocationsWithRequest::class);
    setPrivateProperty($controller, '_request', $request);
    setPrivateProperty($controller, 'pickupLocations', $service);

    return invokePrivateMethod($controller, 'answer', [array_fill(0, $orderCount, createOrder())]);
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
