<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response as GuzzleResponse;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\ShipmentOptions\DeliveryDates;
use MyParcelNL\Sdk\Services\CoreApi\ShipmentApiFactory;

function deliveryDatesWith(array $responses, int $deliveryDaysWindow = 3, string $storeToday = '2026-10-26'): array
{
    $http = makeGuzzleWithHistory($responses);

    $dropOff = Mockery::mock(DropOffSettings::class);
    $dropOff->shouldReceive('dropOffDays')->andReturn([
        (object) ['weekday' => 1, 'cutoffTime' => '17:00'],
        (object) ['weekday' => 2, 'cutoffTime' => '16:30'],
    ]);
    $dropOff->shouldReceive('deliveryDaysWindow')->andReturn($deliveryDaysWindow);
    $dropOff->shouldReceive('dropOffDelay')->andReturn(1);

    return [
        'dates'   => new DeliveryDates(
            shipmentApiProviderWith(ShipmentApiFactory::make('test-key')),
            $dropOff,
            nlShippingAddressQuery(),
            $http['client'],
            datingOn($storeToday)
        ),
        'history' => &$http['history'],
    ];
}

/**
 * One day of a v2 `/delivery_options` answer, in the wire shape. Each possibility is
 * [type, start H:i or null, end H:i or null].
 *
 * @param array<int,array{0:string,1?:?string,2?:?string}> $possibilities
 */
function deliveryDay(string $date, array $possibilities): array
{
    $at = static fn(string $time): array => [
        'date'          => $date . ' ' . $time . ':00.000000',
        'timezone_type' => 3,
        'timezone'      => 'Europe/Amsterdam',
    ];

    return [
        'date'          => $at('00:00'),
        'possibilities' => array_map(static function (array $possibility) use ($at): array {
            [$type, $start, $end] = array_pad($possibility, 3, null);
            $frames               = [];

            if (null !== $start) {
                $frames[] = ['type' => 'start', 'date_time' => $at($start)];
            }

            if (null !== $end) {
                $frames[] = ['type' => 'end', 'date_time' => $at($end)];
            }

            return [
                'type'                 => $type,
                'package_type'         => 'package',
                'delivery_time_frames' => $frames,
                'shipment_options'     => [],
            ];
        }, $possibilities),
    ];
}

/** A v2 `/delivery_options` body as the API sends it. */
function deliveryOptionsBody(array $days): string
{
    return (string) json_encode(['data' => ['deliveries' => $days]]);
}

it('asks with the address, carrier, package type and the checkout drop-off settings', function () {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))]);

    $d['dates']->forOrder(orderShippedTo(['street' => 'Hoofdstraat 12 A']), 'postnl', 'package');

    expect(firstRequestQuery($d['history']))->toMatchArray([
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

it('follows the checkout for the cutoff time and the window', function (string $today, int $window, string $key, string $expected) {
    $d = deliveryDatesWith([new GuzzleResponse(200, [], deliveryOptionsBody([]))], $window, $today);

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');

    expect(firstRequestQuery($d['history'])[$key])->toBe($expected);
})->with([
    'the cutoff time of today when today is a drop-off day' => ['2026-10-27', 3, 'cutoff_time', '16:30'],
    'the default cutoff time when today is no drop-off day' => ['2026-10-25', 3, 'cutoff_time', '16:00'],
    'a week of dates when the checkout shows none'          => ['2026-10-26', 0, 'deliverydays_window', '7'],
]);

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
    deliveryDatesWith([])['dates']->forOrder(createOrder(), 'postnl', 'package');
})->throws(RuntimeException::class);

it('throws on an API error', function () {
    $d = deliveryDatesWith([new GuzzleResponse(422, [], '{"errors":[{"code":3212}]}')]);

    $d['dates']->forOrder(orderShippedTo(), 'postnl', 'package');
})->throws(RuntimeException::class);
