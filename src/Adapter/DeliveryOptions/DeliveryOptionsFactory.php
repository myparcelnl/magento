<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use BadMethodCallException;

/**
 * Picks the stored shape to read a DeliveryOptions from, by its top-level keys only.
 *
 * Each named constructor normalises its own nested keys. Keep throwing BadMethodCallException:
 * two call sites catch that class to fall back to DeliveryOptions::fromOrderFallback().
 */
final class DeliveryOptionsFactory
{
    /** @throws \BadMethodCallException when the data matches no known shape */
    public static function create(array $data): DeliveryOptions
    {
        if (array_key_exists('time', $data) && is_array($data['time'])) {
            return DeliveryOptions::fromLegacyCheckoutData($data);
        }

        if (array_key_exists('deliveryType', $data)) {
            return DeliveryOptions::fromCheckoutData($data);
        }

        throw new BadMethodCallException('Can\'t create DeliveryOptions. No suitable adapter found');
    }
}
