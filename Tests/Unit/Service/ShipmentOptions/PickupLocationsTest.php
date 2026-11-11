<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\ShipmentOptions\PickupLocations;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;

function pickupLocationsWith(array $responses, bool $cartExcludesLockers = false): array
{
    $http = makeGuzzleWithHistory($responses);

    $cartRules = Mockery::mock(CartShippingRules::class);
    $cartRules->shouldReceive('excludesParcelLockers')->andReturn($cartExcludesLockers);

    return [
        'locations' => new PickupLocations(
            shipmentApiProviderWith(new ShipmentApi($http['client'], (new Configuration())->setApiKey('Authorization', 'test-key'))),
            nlShippingAddressQuery(),
            $cartRules
        ),
        'history'   => &$http['history'],
    ];
}

/**
 * One location of a `/pickup_locations` answer, in the wire shape. Each moment is a Y-m-d H:i the
 * location can be collected from.
 *
 * @param string[] $moments
 */
function pickupLocation(string $code, string $name = 'Primera', array $moments = ['2026-10-29 15:00']): array
{
    return [
        'address'       => ['cc' => 'NL', 'city' => 'Hoofddorp', 'street' => 'Antareslaan', 'postal_code' => '2132JE', 'number' => '31', 'number_suffix' => null],
        'possibilities' => array_map(static fn(string $moment): array => [
            'delivery_type_id'   => 4,
            'delivery_type_name' => 'pickup',
            'moment'             => ['start' => ['date' => $moment . ':00.000000', 'timezone_type' => 3, 'timezone' => 'Europe/Amsterdam']],
        ], $moments),
        'location'      => ['location_name' => $name, 'retail_network_id' => 'PNPNL-01', 'distance' => '350', 'location_code' => $code, 'type' => 'retail'],
    ];
}

/** A `/pickup_locations` body as the API sends it. */
function pickupLocationsBody(array $locations): string
{
    return (string) json_encode(['data' => ['pickup_locations' => $locations]]);
}

it('asks with the shipping address, the carrier and the proposition', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))]);

    $p['locations']->near(orderShippedTo(), 'postnl');

    expect(firstRequestQuery($p['history']))->toMatchArray([
        'postal_code' => '1234AB',
        'carrier'     => (string) Carrier::idFor('postnl'),
        'platform'    => Proposition::default()->getName(),
    ])->not->toHaveKey('exclude_parcel_lockers');
});

it('returns every location the API returns, in the shape the order stores', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody(
        array_map(static fn(int $i): array => pickupLocation((string) $i), range(1, 20))
    ))]);

    $locations = $p['locations']->near(orderShippedTo(), 'postnl');

    expect($locations)->toHaveCount(20)
        ->and($locations[0])->toBe(foundPickupLocation('1', '2026-10-29'));
});

it('takes the earliest possibility as the date', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([
        pickupLocation('1', 'Primera', ['2026-10-30 15:00', '2026-10-29 09:00']),
    ]))]);

    expect($p['locations']->near(orderShippedTo(), 'postnl')[0]['date'])->toBe('2026-10-29');
});

it('excludes parcel lockers for an order with an age check', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))]);

    $p['locations']->near(orderShippedTo([], ['age_check' => true]), 'postnl');

    expect(firstRequestQuery($p['history'])['exclude_parcel_lockers'] ?? null)->toBe('1');
});

it('excludes parcel lockers when the cart rules do', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))], true);

    $p['locations']->near(orderShippedTo(), 'postnl');

    expect(firstRequestQuery($p['history'])['exclude_parcel_lockers'] ?? null)->toBe('1');
});

it('finds a location by its code', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([pickupLocation('1'), pickupLocation('2', 'Tweede')]))]);

    expect($p['locations']->find(orderShippedTo(), 'postnl', '2')['location_name'] ?? null)->toBe('Tweede');
});

it('answers null for a code the API does not return', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([pickupLocation('1')]))]);

    expect($p['locations']->find(orderShippedTo(), 'postnl', '99'))->toBeNull();
});

it('throws on an API error', function () {
    pickupLocationsWith([new GuzzleResponse(500, [], '{}')])['locations']->near(orderShippedTo(), 'postnl');
})->throws(RuntimeException::class);
