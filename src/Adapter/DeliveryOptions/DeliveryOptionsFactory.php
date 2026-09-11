<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use BadMethodCallException;

/**
 * Picks which stored shape a DeliveryOptions should be read as: an order carries whatever the
 * checkout wrote when it was placed.
 *
 * Shape detection only. Each named constructor normalises its own nested keys, so this dispatches
 * on the top-level keys, which are camelCase in every shape.
 *
 * Keep throwing BadMethodCallException specifically — two call sites catch that class to fall back
 * to DeliveryOptions::fromOrderFallback().
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
