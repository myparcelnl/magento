<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * The drop-off settings the checkout sends to the delivery options widget, per store.
 *
 * The options modal sends the same settings to `/delivery_options`, so the merchant gets the dates a
 * customer would get. A null store id reads the current store.
 */
class DropOffSettings
{
    private const DELIVERY_DAYS_WINDOW = 'date_settings/deliverydays_window';
    private const DROP_OFF_DELAY       = 'date_settings/dropoff_delay';

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** @return object[] the active drop-off days, each with its weekday (0 is Sunday) and cutoff times */
    public function dropOffDays(string $carrierPath, ?int $storeId = null): array
    {
        $dropOffDays = [];

        for ($weekday = 0; $weekday < 7; $weekday++) {
            if (! $this->config->getBoolConfig($carrierPath, "drop_off_days/day_{$weekday}_active", $storeId)) {
                continue;
            }

            $day               = ['weekday' => $weekday, 'cutoffTime' => $this->config->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_$weekday", $storeId)];
            $cutoffTimeSameDay = $this->config->getTimeConfig($carrierPath, "drop_off_days/cutoff_time_same_day_$weekday", $storeId);

            if ($cutoffTimeSameDay) {
                $day['cutoffTimeSameDay'] = $cutoffTimeSameDay;
            }

            $dropOffDays[] = (object) $day;
        }

        return $dropOffDays;
    }

    /** The number of days the checkout shows dates for; 0 shows none. */
    public function deliveryDaysWindow(?int $storeId = null): int
    {
        return $this->config->getIntegerConfig(Config::XML_PATH_GENERAL, self::DELIVERY_DAYS_WINDOW, $storeId);
    }

    /** The configured days between order and drop-off, before any product adds its own. */
    public function dropOffDelay(?int $storeId = null): int
    {
        return $this->config->getIntegerConfig(Config::XML_PATH_GENERAL, self::DROP_OFF_DELAY, $storeId);
    }
}
