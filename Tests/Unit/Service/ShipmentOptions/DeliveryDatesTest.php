<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryDates;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

// deliveryDay() and deliveryOptionsBody() live in Tests/Helpers/DeliveryChoiceFixtures.php.

/**
 * DeliveryDates over a mocked transport; the SDK still builds the request.
 *
 * Drop-off days are Monday (cutoff 17:00) and Tuesday (cutoff 16:30); 2026-10-26 is a Monday.
 *
 * @param GuzzleResponse[]|Throwable[] $responses
 *
 * @return array{dates: DeliveryDates, history: array}
 */
function deliveryDatesWith(array $responses, int $deliveryDaysWindow = 3, string $storeToday = '2026-10-26'): array
{
    $http = makeGuzzleWithHistory($responses);

    $apiProvider = Mockery::mock(ShipmentApiProvider::class);
    $apiProvider->shouldReceive('apiKeyForStore')->andReturn('test-key');
    $apiProvider->shouldReceive('clientFor')->andReturn(ShipmentApiFactory::make('test-key'));

    $dropOff = Mockery::mock(DropOffSettings::class);
    $dropOff->shouldReceive('dropOffDays')->andReturn([
        (object) ['weekday' => 1, 'cutoffTime' => '17:00'],
        (object) ['weekday' => 2, 'cutoffTime' => '16:30'],
    ]);
    $dropOff->shouldReceive('deliveryDaysWindow')->andReturn($deliveryDaysWindow);
    $dropOff->shouldReceive('dropOffDelay')->andReturn(1);

    $storedAccount = Mockery::mock(StoredAccount::class);
    $storedAccount->shouldReceive('homeCountryForStore')->andReturn('NL');
    $storedAccount->shouldReceive('propositionForStore')->andReturn(null);

    return [
        'dates'   => new DeliveryDates($apiProvider, $dropOff, $storedAccount, $http['client'], datingOn($storeToday)),
        'history' => &$http['history'],
    ];
}

function orderShippedTo(array $address = []): \Magento\Sales\Model\Order
{
    return createOrder(['getShippingAddress' => createAddress($address)]);
}

it('asks with the address, carrier, package type and the checkout drop-off settings', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))]);

    $d['dates']->forOrder(orderShippedTo(['street' => 'Hoofdstraat 12 A']), 'postnl', 'package');

    parse_str($d['history'][0]['request']->getUri()->getQuery(), $query);

    expect($query)->toMatchArray([
        'cc'                  => 'NL',
        'postal_code'         => '1234AB',
        'city'                => 'Amsterdam',
        'street'              => 'Hoofdstraat',
        'number'              => '12',
        'carrier'             => (string) Carrier::idFor('postnl'),
        'package_type'        => 'package',
        'platform'            => Proposition::default()->getName(),
        'dropoff_days'        => '1;2',
        'dropoff_delay'       => '1',
        'deliverydays_window' => '3',
        'cutoff_time'         => '17:00',
    ]);
});

it('sends the cutoff time of today when today is a drop-off day', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))], 3, '2026-10-27');

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');

    parse_str($d['history'][0]['request']->getUri()->getQuery(), $query);

    expect($query['cutoff_time'])->toBe('16:30');
});

it('sends the checkout default cutoff time when today is no drop-off day', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))], 3, '2026-10-25');

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');

    parse_str($d['history'][0]['request']->getUri()->getQuery(), $query);

    expect($query['cutoff_time'])->toBe('16:00');
});

it('asks for a week of dates when the checkout shows none', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))], 0);

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');

    parse_str($d['history'][0]['request']->getUri()->getQuery(), $query);

    expect($query['deliverydays_window'])->toBe('7');
});

it('maps the v2 possibilities to dated entries with their time frame', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([
        deliveryDay('2026-10-29', [['morning', '08:00', '12:00'], ['standard']]),
        deliveryDay('2026-10-30', [['evening', '18:00', '22:00']]),
    ]))]);

    expect($d['dates']->forOrder(orderShippedTo(), 'postnl', 'package'))->toBe([
        datedDelivery('2026-10-29', 'morning', '08:00', '12:00'),
        datedDelivery('2026-10-29', 'standard'),
        datedDelivery('2026-10-30', 'evening', '18:00', '22:00'),
    ]);
});

it('throws without a shipping address', function () {
    $d = deliveryDatesWith([]);

    $d['dates']->forOrder(createOrder(), 'postnl', 'package');
})->throws(RuntimeException::class);

it('throws on an API error', function () {
    $d = deliveryDatesWith([new GuzzleResponse(422, [], '{"errors":[{"code":3212}]}')]);

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');
})->throws(RuntimeException::class);
