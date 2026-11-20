<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use Throwable;

/**
 * Tells what a cart's own items say about how it may ship: forced options, delivery options, drop-off delay.
 *
 * Methods that read config take a carrier config path. With no active carrier, checkout passes the
 * PostNL path, so the path can belong to a carrier that is switched off. Holds no state of its own.
 */
class CartShippingRules
{
    private Config            $config;
    private ProductAttributes $attributes;

    public function __construct(Config $config, ProductAttributes $attributes)
    {
        $this->config     = $config;
        $this->attributes = $attributes;
    }

    /**
     * The limiting options this cart carries whatever the shopper picks, so a package type that
     * cannot carry one of them is not a candidate.
     *
     * @param  \Magento\Quote\Model\Quote\Item[] $items
     * @return string[]
     */
    public function forcedLimitingOptions(array $items, string $carrierPath, ?int $storeId = null): array
    {
        return array_values(array_filter(
            ShipmentOption::LIMIT_PACKAGE_TYPE,
            fn(string $option): bool => $this->optionForcedOn($items, $carrierPath, $option, $storeId)
        ));
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function forcesAgeCheck(array $items, string $carrierPath, ?int $storeId = null): bool
    {
        return $this->optionForcedOn($items, $carrierPath, ShipmentOption::AGE_CHECK, $storeId);
    }

    /**
     * Parcel lockers are excluded by the general setting, by a product that says so, or by a forced
     * age check (on a product or in the carrier's default options).
     *
     * Anything that goes wrong answers "do not exclude", so a failed read never hides pickup locations.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function excludesParcelLockers(array $items, string $carrierPath, ?int $storeId = null): bool
    {
        try {
            $this->attributes->preloadItems($items);

            if ((bool) $this->config->getGeneralConfig('shipping_methods/exclude_parcel_lockers', $storeId)) {
                return true;
            }

            foreach ($items as $item) {
                if ((bool) $this->attributes->itemValue($item, 'exclude_parcel_lockers')) {
                    return true;
                }
            }

            return $this->forcesAgeCheck($items, $carrierPath, $storeId);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function allowsPriorityDelivery(array $items, string $carrierPath, ?int $storeId = null): bool
    {
        if ((bool) $this->config->getConfigValue($carrierPath . 'mailbox/priority_delivery_active', $storeId)) {
            return true;
        }

        $this->attributes->preloadItems($items);

        foreach ($items as $item) {
            if ((bool) $this->attributes->itemValue($item, 'priority_delivery')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether any item in this cart forbids the delivery options widget.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function hidesDeliveryOptions(array $items): bool
    {
        $this->attributes->preloadItems($items);

        foreach ($items as $item) {
            if ((bool) $this->attributes->itemValue($item, 'disable_checkout')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the longest drop-off delay any item asks for, or null when none does.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function dropOffDelay(array $items): ?int
    {
        $this->attributes->preloadItems($items);

        $highest = null;

        foreach ($items as $item) {
            $delay = $this->attributes->itemValue($item, 'dropoff_delay');

            if ($delay > $highest) {
                $highest = $delay;
            }
        }

        return $highest > 0 ? $highest : null;
    }

    /**
     * Check whether a product in the cart or the carrier's default options switch the option on.
     * A missing attribute or config path is off.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    private function optionForcedOn(array $items, string $carrierPath, string $option, ?int $storeId): bool
    {
        $this->attributes->preloadItems($items);

        foreach ($items as $item) {
            if ((bool) $this->attributes->itemValue($item, $option)) {
                return true;
            }
        }

        // Not a cast: LargeFormatOptions stores 'price', which casts to true.
        return '1' === (string) $this->config->getConfigValue(
            $carrierPath . 'default_options/' . $option . '_active',
            $storeId
        );
    }
}
