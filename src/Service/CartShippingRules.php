<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use Throwable;

/**
 * What a cart's own items say about how it may ship: which options it forces on, whether it may be
 * offered delivery options at all, and how long it delays drop-off.
 *
 * Every method that reads configuration takes a carrier **path**, not a name. Checkout asks these
 * questions for the first active carrier, and falls back to the PostNL path when no carrier is
 * active at all — so the path can belong to no carrier. Do not harmonise this with
 * PackageTypeResolver, which takes a name.
 *
 * Nothing is remembered between calls. The predecessor memoised on the quote items because it was a
 * shared singleton and would otherwise answer one order from another order's products; without the
 * sharing there is nothing to defend against, and the product reads are batched anyway.
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
     * Parcel lockers are excluded by the general setting, by a product that says so, or by 18+ goods.
     *
     * Anything that goes wrong answers "do not exclude": this decides whether a delivery method is
     * offered, and failing closed would hide pickup locations over a config read.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function excludesParcelLockers(array $items, string $carrierPath, ?int $storeId = null): bool
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

        $this->preloadAttributes($items);

        foreach ($items as $item) {
            if ((bool) $this->attributeOf($item, 'priority_delivery')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any item in this cart forbids the delivery options widget.
     *
     * A predicate, not a flag. Its predecessor set a public boolean that was never reset, so on a
     * shared instance one disabling cart silenced every later cart in the same request.
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
     * The longest drop-off delay any item asks for, or null when none does.
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
     * Check whether a product in the cart or the carrier's default options switch the option on. A
     * missing attribute or config path is off.
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
