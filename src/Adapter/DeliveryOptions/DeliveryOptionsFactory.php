<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter\DeliveryOptions;

use BadMethodCallException;
use MyParcelNL\Sdk\Support\Str;

/**
 * Builds a DeliveryOptions from stored data whose shape is not known in advance: an order carries
 * whatever the checkout wrote when it was placed.
 *
 * Keep throwing BadMethodCallException specifically — two call sites catch that class to fall back
 * to DeliveryOptions::fromOrderFallback().
 */
final class DeliveryOptionsFactory
{
    /** @throws \BadMethodCallException when the data matches no known shape */
    public static function create(array $data): DeliveryOptions
    {
        $data = self::snakeCaseNestedKeys($data);

        if (array_key_exists('time', $data) && is_array($data['time'])) {
            return DeliveryOptions::fromLegacyCheckoutData($data);
        }

        if (array_key_exists('deliveryType', $data)) {
            return DeliveryOptions::fromCheckoutData($data);
        }

        throw new BadMethodCallException('Can\'t create DeliveryOptions. No suitable adapter found');
    }

    /**
     * The widget sends these two nested objects in camelCase, and a toArray() round trip writes them
     * back in snake_case, so both spellings exist in the database. The top level stays camelCase.
     */
    private static function snakeCaseNestedKeys(array $data): array
    {
        foreach (['shipmentOptions', 'pickupLocation'] as $nested) {
            if (! isset($data[$nested]) || ! is_array($data[$nested])) {
                continue;
            }

            foreach ($data[$nested] as $key => $value) {
                $snakeCased = Str::snake((string) $key);

                if ($snakeCased === $key) {
                    continue;
                }

                unset($data[$nested][$key]);
                $data[$nested][$snakeCased] = $value;
            }
        }

        return $data;
    }
}
