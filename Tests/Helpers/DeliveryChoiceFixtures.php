<?php

declare(strict_types=1);

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

/** A dated entry as DeliveryDates::forOrder() returns it. */
function datedDelivery(string $date, string $deliveryType, ?string $start = null, ?string $end = null): array
{
    return ['date' => $date, 'deliveryType' => $deliveryType, 'start' => $start, 'end' => $end];
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
