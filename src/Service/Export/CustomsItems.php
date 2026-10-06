<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\ProductAttributes;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Support\Str;

/**
 * The customs facts both export paths share: HS codes, countries of origin, and the line arithmetic.
 *
 * Each path keeps its own item source: a label declares its shipment items at the base price
 * (assumed EUR), a fulfilment order its order lines at the price paid in the order currency. Both
 * lookups take every product id at once, never one query per item.
 */
class CustomsItems
{
    /** The API's maximum. setDescription() throws above it, so a longer name is shortened. */
    private const MAX_DESCRIPTION_LENGTH = 50;

    /** Magento's configurable product type code, without a dependency on Magento_ConfigurableProduct. */
    public const CONFIGURABLE = 'configurable';

    /** The HS code length sent: TARIC, the Order API maximum. See "HS code length" in docs/sdk-v11.md. */
    private const MAX_CLASSIFICATION_LENGTH = 10;

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
     * HS codes: up to 18 characters, digits and dots (6109.10).
     *
     * @param  int[] $productIds
     * @return array<int,string> product id => HS code
     */
    public function classificationsFor(array $productIds): array
    {
        return $this->attributes()->getColumn($productIds, 'classification');
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
     * The HS code and country of origin of each customs line.
     *
     * A line lists its products most specific first: a configurable item's variant, then its parent.
     * The first product with a value wins. A country falls back to the store's `print/country_of_origin`
     * setting, then to the account's home country; an HS code has no fallback.
     *
     * @return array<int|string, array{classification: string, country: string}>
     */
    public function customsDataFor(array $productIdsByLine, int $storeId): array
    {
        $productIds = [];

        foreach ($productIdsByLine as $ids) {
            array_push($productIds, ...$ids);
        }

        $productIds      = array_values(array_unique($productIds));
        $classifications = $productIds ? $this->classificationsFor($productIds) : [];
        $countries       = $productIds ? $this->manufacturingCountriesFor($productIds) : [];
        $fallback        = (string) $this->config->getGeneralConfig('print/country_of_origin', $storeId)
            ?: $this->objectManager->get(StoredAccount::class)->homeCountryForStore($storeId);

        $data = [];

        foreach ($productIdsByLine as $line => $ids) {
            $data[$line] = [
                'classification' => self::firstOf($ids, $classifications) ?? '',
                'country'        => self::firstOf($ids, $countries) ?? $fallback,
            ];
        }

        return $data;
    }

    /** Product id => country of manufacture, only for a product that has one. */
    private function manufacturingCountriesFor(array $productIds): array
    {
        /** @var ProductCollection $collection */
        $collection = $this->objectManager->create(ProductCollection::class);
        $collection->addIdFilter($productIds)
                   ->addAttributeToSelect('country_of_manufacture');

        $countries = [];

        foreach ($collection->getItems() as $product) {
            $country = (string) $product->getCountryOfManufacture();

            if ('' !== $country) {
                $countries[(int) $product->getId()] = $country;
            }
        }

        return $countries;
    }

    private static function firstOf(array $ids, array $values): ?string
    {
        foreach ($ids as $id) {
            if ('' !== (string) ($values[$id] ?? '')) {
                return (string) $values[$id];
            }
        }

        return null;
    }

    /**
     * Check whether an order row is one a label ships: not a dummy row, such as the child of a
     * configurable or the parent of a bundle shipped separately, and not a virtual product.
     *
     * @param \Magento\Sales\Model\Order\Item $orderItem
     */
    public static function isShippedRow($orderItem): bool
    {
        return ! $orderItem->isDummy(true) && ! $orderItem->getIsVirtual();
    }

    /** The SDK's refusal of a customs item, with the product named so the merchant can find it. */
    public static function refused(string $name, ?string $sku, \InvalidArgumentException $e): \RuntimeException
    {
        $product = null === $sku || '' === $sku ? sprintf('"%s"', $name) : sprintf('"%s" (%s)', $name, $sku);

        return new \RuntimeException((string) __('customs item %1: %2', $product, $e->getMessage()), 0, $e);
    }

    public function description(string $name): string
    {
        return Str::limit($name, self::MAX_DESCRIPTION_LENGTH);
    }

    /** The HS code as digits only, cut to its parent code at MAX_CLASSIFICATION_LENGTH digits. */
    public function classification(string $classification): string
    {
        return substr((string) preg_replace('/\D/', '', $classification), 0, self::MAX_CLASSIFICATION_LENGTH);
    }

    /** Weight and value are line-level while amount carries the count, so both multiply by quantity. */
    public function lineWeightInGrams(float $unitWeight, float $quantity): int
    {
        // The API refuses a zero-gram customs item; convertToGrams() gives a weightless line Weight::DEFAULT_WEIGHT.
        return $this->weight->convertToGrams($unitWeight * $quantity);
    }

    /** Cents are multiplied rather than euros, so one rounding happens instead of one per line. */
    public function lineValueInCents(float $unitPrice, float $quantity): int
    {
        return DeliveryCosts::roundHalfUp(DeliveryCosts::getPriceInCents($unitPrice) * $quantity);
    }
}
