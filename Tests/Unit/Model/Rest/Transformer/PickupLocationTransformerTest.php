<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\PickupLocationTransformer;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractPickupLocationAdapter;

function pickupLocationMock(array $overrides = []): AbstractPickupLocationAdapter
{
    $defaults = [
        'getLocationCode'    => 'LOC-1',
        'getLocationName'    => 'Test location',
        'getRetailNetworkId' => 'NET-1',
        'getStreet'          => 'Main street',
        'getNumber'          => '42',
        'getPostalCode'      => '1234AB',
        'getCity'            => 'Amsterdam',
        'getCountry'         => 'NL',
    ];

    $mock = Mockery::mock(AbstractPickupLocationAdapter::class);
    foreach (array_merge($defaults, $overrides) as $method => $value) {
        $mock->shouldReceive($method)->andReturn($value);
    }

    return $mock;
}

it('returns null for null input', function () {
    expect((new PickupLocationTransformer())->transform(null))->toBeNull();
});

it('maps adapter getters to the output object', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationMock());

    expect($result)->toBeObject();
    expect($result->locationCode)->toBe('LOC-1');
    expect($result->locationName)->toBe('Test location');
    expect($result->retailNetworkId)->toBe('NET-1');

    expect($result->address)->toBeObject();
    expect($result->address->street)->toBe('Main street');
    expect($result->address->number)->toBe('42');
    expect($result->address->postalCode)->toBe('1234AB');
    expect($result->address->city)->toBe('Amsterdam');
    expect($result->address->cc)->toBe('NL');
});

it('omits fields the adapter cannot supply', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationMock());

    expect(property_exists($result, 'type'))->toBeFalse();
    expect(property_exists($result->address, 'numberSuffix'))->toBeFalse();
    expect(property_exists($result->address, 'boxNumber'))->toBeFalse();
    expect(property_exists($result->address, 'state'))->toBeFalse();
    expect(property_exists($result->address, 'region'))->toBeFalse();
});

it('propagates a null country as cc', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationMock(['getCountry' => null]));

    expect($result->address->cc)->toBeNull();
});
