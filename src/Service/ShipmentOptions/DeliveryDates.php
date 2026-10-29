<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\ShipmentOptions;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Dating;
use MyParcelNL\Magento\Service\DropOffSettings;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use RuntimeException;
use Throwable;

/**
 * The dated deliveries `GET /delivery_options` offers for an order's shipping address.
 *
 * The SDK builds the request, but its typed answer loses each possibility's delivery type (the
 * generated wrapper model has no properties), so the raw JSON body is read instead.
 */
class DeliveryDates
{
    private const TIMEOUT_SECONDS = 10;

    /** Days to ask for when the checkout shows no dates: morning and evening still need one. */
    private const DEFAULT_DELIVERY_DAYS_WINDOW = 7;

    /** The checkout widget's cutoff time when today is no drop-off day (CUTOFF_TIME_DEFAULT). */
    private const DEFAULT_CUTOFF_TIME = '16:00';

    private ShipmentApiProvider  $apiProvider;
    private DropOffSettings      $dropOffSettings;
    private ShippingAddressQuery $addressQuery;
    private GuzzleClient         $httpClient;
    private Dating               $dating;

    public function __construct(
        ShipmentApiProvider  $apiProvider,
        DropOffSettings      $dropOffSettings,
        ShippingAddressQuery $addressQuery,
        GuzzleClient         $httpClient,
        Dating               $dating
    ) {
        $this->apiProvider     = $apiProvider;
        $this->dropOffSettings = $dropOffSettings;
        $this->addressQuery    = $addressQuery;
        $this->httpClient      = $httpClient;
        $this->dating          = $dating;
    }

    /**
     * @return array<int,array{date:string,deliveryType:string,start:?string,end:?string}> module
     *         delivery type names, dates as Y-m-d, times as H:i
     * @throws RuntimeException when the dates cannot be read
     */
    public function forOrder(Order $order, string $carrier, string $packageType): array
    {
        $query     = $this->addressQuery->forOrder($order);
        $storeId   = (int) $order->getStoreId();
        $carrierId = Carrier::idFor($carrier);

        if (null === $carrierId) {
            throw new RuntimeException(sprintf('carrier "%s" is not one the SDK knows', $carrier));
        }

        try {
            $window   = $this->dropOffSettings->deliveryDaysWindow($storeId);
            $dropOff  = $this->dropOffSettings->dropOffDays(Config::carrierPath($carrier), $storeId);
            $days     = array_map(static fn(object $day): int => $day->weekday, $dropOff);
            $request  = $this->apiProvider->clientFor($this->apiProvider->apiKeyForStore($storeId))->getDeliveryOptionsRequest(
                $query['cc'],
                $query['postal_code'],
                $query['number'],
                $query['city'],
                $query['street'],
                $query['platform'],
                null,
                (string) $carrierId,
                null,
                null,
                $this->cutoffTime($dropOff, (int) $this->dating->today($order, 'w')),
                [] === $days ? null : implode(';', $days),
                null,
                $this->dropOffSettings->dropOffDelay($storeId),
                0 < $window ? $window : self::DEFAULT_DELIVERY_DAYS_WINDOW,
                null,
                null,
                null,
                null,
                $packageType
            );
            $response = $this->httpClient->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT     => self::TIMEOUT_SECONDS,
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException('the delivery options could not be requested: ' . $e->getMessage(), 0, $e);
        }

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(sprintf('the delivery options answered %d', $response->getStatusCode()));
        }

        return self::dated(json_decode((string) $response->getBody(), true)['data']['deliveries'] ?? []);
    }

    /** As the checkout widget does: the cutoff time of today's drop-off day, else its default. */
    private function cutoffTime(array $dropOffDays, int $weekday): string
    {
        foreach ($dropOffDays as $day) {
            if ($weekday === $day->weekday && '' !== (string) $day->cutoffTime) {
                return $day->cutoffTime;
            }
        }

        return self::DEFAULT_CUTOFF_TIME;
    }

    /** @return array<int,array{date:string,deliveryType:string,start:?string,end:?string}> */
    private static function dated(array $deliveries): array
    {
        $dated = [];

        foreach ($deliveries as $delivery) {
            $date = substr((string) ($delivery['date']['date'] ?? ''), 0, 10);

            if ('' === $date) {
                continue;
            }

            foreach ($delivery['possibilities'] ?? [] as $possibility) {
                if (! is_string($possibility['type'] ?? null)) {
                    continue;
                }

                $frames = array_column(
                    array_map(static fn(array $frame): array => [
                        'type' => $frame['type'] ?? '',
                        'time' => substr((string) ($frame['date_time']['date'] ?? ''), 11, 5) ?: null,
                    ], $possibility['delivery_time_frames'] ?? []),
                    'time',
                    'type'
                );

                $dated[] = [
                    'date'         => $date,
                    'deliveryType' => $possibility['type'],
                    'start'        => $frames['start'] ?? null,
                    'end'          => $frames['end'] ?? null,
                ];
            }
        }

        return $dated;
    }
}
