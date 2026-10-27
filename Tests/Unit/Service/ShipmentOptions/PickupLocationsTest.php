<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\CartShippingRules;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\ShipmentOptions\PickupLocations;
use MyParcelNL\Magento\Service\ShipmentOptions\ShippingAddressQuery;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Api\ShipmentApi;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Configuration;

// pickupLocation() and pickupLocationsBody() live in Tests/Helpers/DeliveryChoiceFixtures.php.

/**
 * PickupLocations over a mocked transport; the SDK sends and reads.
 *
 * @param GuzzleResponse[]|Throwable[] $responses
 *
 * @return array{locations: PickupLocations, history: array}
 */
function pickupLocationsWith(array $responses, bool $cartExcludesLockers = false): array
{
    $http = makeGuzzleWithHistory($responses);

    $apiProvider = Mockery::mock(ShipmentApiProvider::class);
    $apiProvider->shouldReceive('apiKeyForStore')->andReturn('test-key');
    $apiProvider->shouldReceive('clientFor')->andReturn(
        new ShipmentApi($http['client'], (new Configuration())->setApiKey('Authorization', 'test-key'))
    );

    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('homeCountryForStore')->andReturn('NL');
    $storedAccount->shouldReceive('propositionForStore')->andReturn(null);

    $cartRules = Mockery::mock(CartShippingRules::class);
    $cartRules->shouldReceive('excludesParcelLockers')->andReturn($cartExcludesLockers);

    return [
        'locations' => new PickupLocations($apiProvider, new ShippingAddressQuery($storedAccount), $cartRules),
        'history'   => &$http['history'],
    ];
}

function pickupOrder(array $shipmentOptions = []): \Magento\Sales\Model\Order
{
    return createOrder([
        'getShippingAddress'  => createAddress(['street' => 'Hoofdstraat 12']),
        'getAllVisibleItems'  => [],
        'deliveryOptions'     => json_encode(['carrier' => 'postnl', 'deliveryType' => 'standard', 'shipmentOptions' => $shipmentOptions]),
    ]);
}

function pickupQuery(array $history): array
{
    parse_str($history[0]['request']->getUri()->getQuery(), $query);

    return $query;
}

it('asks with the shipping address, the carrier and the proposition', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))]);

    $p['locations']->near(pickupOrder(), 'postnl');

    expect(pickupQuery($p['history']))->toMatchArray([
        'cc'          => 'NL',
        'postal_code' => '1234AB',
        'number'      => '12',
        'street'      => 'Hoofdstraat',
        'city'        => 'Amsterdam',
        'carrier'     => (string) Carrier::idFor('postnl'),
        'platform'    => Proposition::default()->getName(),
    ])->not->toHaveKey('exclude_parcel_lockers');
});

it('returns every location the API returns, in the shape the order stores', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody(
        array_map(static fn(int $i): array => pickupLocation((string) $i), range(1, 20))
    ))]);

    $locations = $p['locations']->near(pickupOrder(), 'postnl');

    expect($locations)->toHaveCount(20)
        ->and($locations[0])->toBe([
            'location_code'     => '1',
            'location_name'     => 'Primera',
            'retail_network_id' => 'PNPNL-01',
            'street'            => 'Antareslaan',
            'number'            => '31',
            'postal_code'       => '2132JE',
            'city'              => 'Hoofddorp',
            'cc'                => 'NL',
            'distance'          => '350',
            'date'              => '2026-10-29',
        ]);
});

it('takes the earliest possibility as the date', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([
        pickupLocation('1', 'Primera', ['2026-10-30 15:00', '2026-10-29 09:00']),
    ]))]);

    expect($p['locations']->near(pickupOrder(), 'postnl')[0]['date'])->toBe('2026-10-29');
});

it('excludes parcel lockers for an order with an age check', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))]);

    $p['locations']->near(pickupOrder(['age_check' => true]), 'postnl');

    expect(pickupQuery($p['history'])['exclude_parcel_lockers'] ?? null)->toBe('1');
});

it('excludes parcel lockers when the cart rules do', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([]))], true);

    $p['locations']->near(pickupOrder(), 'postnl');

    expect(pickupQuery($p['history'])['exclude_parcel_lockers'] ?? null)->toBe('1');
});

it('finds a location by its code', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([pickupLocation('1'), pickupLocation('2', 'Tweede')]))]);

    expect($p['locations']->find(pickupOrder(), 'postnl', '2')['location_name'] ?? null)->toBe('Tweede');
});

it('answers null for a code the API does not return', function () {
    $p = pickupLocationsWith([new GuzzleResponse(200, [], pickupLocationsBody([pickupLocation('1')]))]);

    expect($p['locations']->find(pickupOrder(), 'postnl', '99'))->toBeNull();
});

it('throws without a shipping address', function () {
    pickupLocationsWith([])['locations']->near(createOrder(), 'postnl');
})->throws(RuntimeException::class);

it('throws on an API error', function () {
    pickupLocationsWith([new GuzzleResponse(500, [], '{}')])['locations']->near(pickupOrder(), 'postnl');
})->throws(RuntimeException::class);
