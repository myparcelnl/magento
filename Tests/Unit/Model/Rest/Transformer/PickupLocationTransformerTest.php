<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\PickupLocationTransformer;

it('returns null for null input', function () {
    expect((new PickupLocationTransformer())->transform(null))->toBeNull();
});

it('maps adapter getters to the output object', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationFixture());

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

it('exposes the reserved type field as null', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationFixture());

    expect($result->type)->toBeNull();
});

it('omits address fields the transformer does not populate', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationFixture());

    expect(property_exists($result->address, 'numberSuffix'))->toBeFalse();
    expect(property_exists($result->address, 'boxNumber'))->toBeFalse();
    expect(property_exists($result->address, 'state'))->toBeFalse();
    expect(property_exists($result->address, 'region'))->toBeFalse();
});

/**
 * PickupLocation defaults an unset country to '', so a null one is unreachable. The transformer
 * passes the empty string through rather than inventing a country.
 */
it('passes an unset country through without substituting one', function () {
    $result = (new PickupLocationTransformer())->transform(pickupLocationFixture(['getCountry' => null]));

    expect($result->address->cc)->toBe('');
});
