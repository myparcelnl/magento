<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\ProductAttributes;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Support\Str;

/**
 * The customs facts both export paths share: HS codes, countries of origin, and the line arithmetic.
 *
 * The two paths keep their own item source on purpose. A shipment declares what was shipped, with
 * the item's own weight and price; a fulfilment order declares what was ordered, with the product's.
 * Only the lookups and the per-line maths are the same, and only those live here.
 *
 * Both lookups take every product id at once. Asking per item is what made a 40-line order outside
 * the EU cost 120 queries.
 */
class CustomsItems
{
    /** Sent by the legacy encoder for every shipment; the module never chose another value. */
    public const CONTENTS_COMMERCIAL_GOODS = 1;

    public const MAX_DESCRIPTION_LENGTH = 50;

    /** The HS code attribute's own cap; the API declares no maximum of its own. */
    private const MAX_CLASSIFICATION_LENGTH = 18;

    private ObjectManagerInterface $objectManager;
    private Config                 $config;
    private Weight                 $weight;

    private ?ProductAttributes $attributes = null;

    public function __construct(ObjectManagerInterface $objectManager, Config $config, Weight $weight)
    {
        $this->objectManager = $objectManager;
        $this->config        = $config;
        $this->weight        = $weight;
    }

    /**
     * HS codes: up to 18 characters, digits and dots (6109.10). The int column they moved from
     * dropped leading zeroes and dots.
     *
     * @param  int[] $productIds
     * @return array<int,string> product id => HS code
     */
    public function classificationsFor(array $productIds): array
    {
        return $this->attributes()->column($productIds, 'classification');
    }

    /** Held for the instance, which is what keeps a multi-item declaration to one product load. */
    private function attributes(): ProductAttributes
    {
        if (null === $this->attributes) {
            $this->attributes = new ProductAttributes($this->objectManager);
        }

        return $this->attributes;
    }

    /**
     * Product setting first, the `print/country_of_origin` setting second.
     *
     * @param  int[] $productIds
     * @return array<int,string> product id => ISO country
     */
    public function countriesOfOriginFor(array $productIds): array
    {
        $fallback = (string) $this->config->getGeneralConfig('print/country_of_origin');

        /** @var ProductCollection $collection */
        $collection = $this->objectManager->create(ProductCollection::class);
        $collection->addIdFilter($productIds)
                   ->addAttributeToSelect('country_of_manufacture');

        $countries = [];

        foreach ($collection->getItems() as $product) {
            $countries[(int) $product->getId()] = (string) ($product->getCountryOfManufacture() ?: $fallback);
        }

        // A product that no longer exists still needs a country on its customs item.
        return $countries + array_fill_keys($productIds, $fallback);
    }

    public function description(string $name): string
    {
        return Str::limit($name, self::MAX_DESCRIPTION_LENGTH);
    }

    public function classification(string $classification): string
    {
        return substr($classification, 0, self::MAX_CLASSIFICATION_LENGTH);
    }

    /**
     * Weight and value are line-level while amount carries the count, so both multiply by quantity.
     *
     * @param float $quantity kept as a float: a fulfilment order ships qtyShipped, which can be one
     */
    public function lineWeightInGrams(float $unitWeight, float $quantity): int
    {
        // A zero-gram customs item is refused by the API, so an item with no weight set counts as 1.
        return $this->weight->convertToGrams($unitWeight * $quantity) ?: 1;
    }

    /** Cents are multiplied rather than euros, so one rounding happens instead of one per line. */
    public function lineValueInCents(float $unitPrice, float $quantity): int
    {
        return DeliveryCosts::roundHalfUp(DeliveryCosts::getPriceInCents($unitPrice) * $quantity);
    }
}
