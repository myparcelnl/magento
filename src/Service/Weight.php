<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Model\Shipment\PackageType;

class Weight
{
    public const DEFAULT_WEIGHT = 1000;

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Returns weight in grams based on configured weight indication, defaults to self::DEFAULT_WEIGHT
     *
     * @param null|float $weight
     *
     * @return int
     */
    public function convertToGrams(?float $weight): int
    {
        if ($this->unitIsKilo()) {
            return (int) ($weight * 1000) ?: self::DEFAULT_WEIGHT;
        }

        return (int) $weight ?: self::DEFAULT_WEIGHT;
    }

    /** Check whether the merchant weighs products in kilos; the setting exists at default scope only. */
    public function unitIsKilo(): bool
    {
        return 'kilo' === $this->config->getGeneralConfig('print/weight_indication');
    }

    /**
     * Get the weight of the items in the merchant's own unit. Skips items with qty < 1 or no weight.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function itemsWeight(array $items): float
    {
        $weight = 0.0;

        foreach ($items as $item) {
            $itemQty    = (float) $item->getQty();
            $itemWeight = (float) $item->getWeight();

            if ($itemQty < 1 || $itemWeight <= 0) {
                continue;
            }

            $weight += $itemWeight * $itemQty;
        }

        return $weight;
    }

    /**
     * Returns configured empty weight in grams, defaults to 0 when not found or not set
     *
     * @param int $packageType
     * @return int
     */
    public function getEmptyPackageWeightInGrams(int $packageType): int
    {
        // A type with no name has no config path either, so it weighs nothing extra.
        $packageTypeName = PackageType::nameFromIdOrNull($packageType);

        if (null === $packageTypeName) {
            return 0;
        }

        return (int) $this->config->getGeneralConfig("empty_package_weight/$packageTypeName");
    }

    /**
     * Returns weight of products in Quote in grams, skips products with quantity < 1 or weight <= 0
     *
     * @param Quote $quote
     * @return int
     */
    public function getQuoteWeightInGrams(Quote $quote): int
    {
        return $this->convertToGrams($this->itemsWeight($quote->getAllItems()));
    }
}
