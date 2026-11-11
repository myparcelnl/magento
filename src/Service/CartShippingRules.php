<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use Throwable;

/**
 * Tells what a cart's own items say about how it may ship: forced options, delivery options, drop-off delay.
 *
 * Methods that read config take a carrier **path**, not a name. With no active carrier, checkout
 * passes the PostNL path, so the path can belong to no carrier. Do not harmonise this with
 * PackageTypeResolver, which takes a name. Stateless: it remembers nothing between calls.
 */
class CartShippingRules
{
    use ReadsProductAttributes;

    private Config $config;

    public function __construct(Config $config, ProductAttributes $attributes)
    {
        $this->config     = $config;
        $this->attributes = $attributes;
    }

    /**
     * Get the keepAtCheckout options that a product or the carrier's default option setting forces on.
     *
     * @param  \Magento\Quote\Model\Quote\Item[] $items
     * @return string[]
     */
    public function forcedCheckoutOptions(array $items, string $carrierPath, ?int $storeId = null): array
    {
        return array_values(array_filter(
            ShipmentOption::having('keepAtCheckout'),
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
     * Check whether parcel lockers are excluded: by the general setting, a product, or 18+ goods.
     * A null carrier path skips the 18+ rule. An error answers "do not exclude", so a failed config
     * read does not hide pickup locations.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function excludesParcelLockers(array $items, ?string $carrierPath, ?int $storeId = null): bool
    {
        try {
            $this->preloadAttributes($items);

            if ((bool) $this->config->getGeneralConfig('shipping_methods/exclude_parcel_lockers', $storeId)) {
                return true;
            }

            foreach ($items as $item) {
                if ((bool) $this->attributeOf($item, 'exclude_parcel_lockers')) {
                    return true;
                }
            }

            return null !== $carrierPath && $this->forcesAgeCheck($items, $carrierPath, $storeId);
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

        $this->preloadAttributes($items);

        foreach ($items as $item) {
            if ((bool) $this->attributeOf($item, 'priority_delivery')) {
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
        $this->preloadAttributes($items);

        foreach ($items as $item) {
            if ((bool) $this->attributeOf($item, 'disable_checkout')) {
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
        $this->preloadAttributes($items);

        $highest = null;

        foreach ($items as $item) {
            $delay = $this->attributeOf($item, 'dropoff_delay');

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
        $this->preloadAttributes($items);

        foreach ($items as $item) {
            if ((bool) $this->attributeOf($item, $option)) {
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
