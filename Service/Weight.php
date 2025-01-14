<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

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
        $weightType = $this->config->getGeneralConfig('print/weight_indication');

        if ('kilo' === $weightType) {
            return (int)($weight * 1000) ?: self::DEFAULT_WEIGHT;
        }

        return (int)$weight ?: self::DEFAULT_WEIGHT;
    }

    /**
     * Returns configured empty weight in grams, defaults to 0 when not found or not set
     *
     * @param string $packageType
     * @return int
     */
    public function getEmptyPackageWeightInGrams(int $packageType): int
    {
        if (!in_array($packageType, AbstractConsignment::PACKAGE_TYPES_IDS, true)) {
            return 0;
        }
        // todo if this array_flip is used in multiple places, consider making a method or something else
        $packageTypeName = array_flip(AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP)[$packageType];

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
        $products = $quote->getAllItems();
        $weight   = 0;

        foreach ($products as $product) {
            $productQty    = (float)$product->getQty();
            $productWeight = (float)$product->getWeight();

            if ($productQty < 1 || $productWeight <= 0) {
                continue;
            }

            $weight += $productWeight * $productQty;
        }

        return $this->convertToGrams($weight);
    }
}
