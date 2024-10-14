<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Weight;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class WeightService
{
    public const DEFAULT_WEIGHT = 1000;

    private ConfigService $configService;

    public function __construct(ConfigService $configService)
    {
        $this->configService = $configService;
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
        $weightType = $this->configService->getGeneralConfig('print/weight_indication');

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
    public function getEmptyPackageWeightInGrams(string $packageType): int
    {
        if (!in_array($packageType, AbstractConsignment::PACKAGE_TYPES_NAMES)) {
            return 0;
        }

        return $this->configService->getGeneralConfig("$packageType/empty_package_weight") ?: 0;
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
