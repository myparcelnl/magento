<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;

/**
 * Reads the module's own `myparcel_*` product attributes for a batch of products, all in one load.
 *
 * Read through the product collection, never the EAV value tables directly: Adobe Commerce keys
 * those tables on `row_id` and holds one row per scheduled update.
 */
class ProductAttributes
{
    private const PREFIX = 'myparcel_';

    /** Every product attribute UpgradeData installs. Fetched together. */
    private const ATTRIBUTES = [
        'age_check',
        'classification',
        'digital_stamp',
        'disable_checkout',
        'dropoff_delay',
        'exclude_parcel_lockers',
        'fit_in_mailbox',
        'priority_delivery',
    ];

    private ObjectManagerInterface $objectManager;

    /** @var array<int,array<string,string>> product id => column => value; a loaded product without values is [] */
    private array $loaded = [];

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /** Get the value of `myparcel_<$column>` for one product, or null when it has none. */
    public function getValue(int $productId, string $column): ?string
    {
        $this->preload([$productId]);

        return $this->loaded[$productId][$column] ?? null;
    }

    /**
     * Get one column for a batch of products. A product without a value is not in the map: do not
     * fill it with an empty string, because each caller reads a missing key as "no opinion".
     *
     * @param  int[] $productIds
     * @return array<int,string> product id => value
     */
    public function getColumn(array $productIds, string $column): array
    {
        $this->preload($productIds);

        $values = [];

        foreach ($productIds as $productId) {
            $value = $this->loaded[(int) $productId][$column] ?? null;

            if (null !== $value) {
                $values[(int) $productId] = $value;
            }
        }

        return $values;
    }

    /**
     * Load the products of a list of quote items in one query. Call it before itemValue() per item.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    public function preloadItems(array $items): void
    {
        $productIds = [];

        foreach ($items as $item) {
            $productId = self::productIdOf($item);

            if (null !== $productId) {
                $productIds[] = $productId;
            }
        }

        $this->preload($productIds);
    }

    /**
     * Get `myparcel_<$column>` of a quote item's product as an int, or null when it has no value.
     * An item whose product was deleted from the catalogue answers null.
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    public function itemValue($item, string $column): ?int
    {
        $productId = self::productIdOf($item);

        if (null === $productId) {
            return null;
        }

        $value = $this->getValue($productId, $column);

        return null === $value ? null : (int) $value;
    }

    /**
     * Load every attribute of the products not loaded yet, in one query. Call it with the whole batch
     * before you read product by product.
     *
     * @param int[] $productIds
     */
    public function preload(array $productIds): void
    {
        $missing = [];

        foreach ($productIds as $productId) {
            $productId = (int) $productId;

            if (! isset($this->loaded[$productId])) {
                $missing[$productId] = $productId;
            }
        }

        if (! $missing) {
            return;
        }

        // Seeded before the query, so a product the collection does not return (deleted, or out of
        // this store) counts as loaded and is never asked for again.
        foreach ($missing as $productId) {
            $this->loaded[$productId] = [];
        }

        /** @var ProductCollection $collection */
        $collection = $this->objectManager->create(ProductCollection::class);
        $collection->addIdFilter(array_values($missing))
                   ->addAttributeToSelect(array_map(
                       static function (string $column): string {
                           return self::PREFIX . $column;
                       },
                       self::ATTRIBUTES
                   ));

        foreach ($collection->getItems() as $product) {
            $values = [];

            foreach (self::ATTRIBUTES as $column) {
                $value = $product->getData(self::PREFIX . $column);

                // An empty string is a row that says nothing, which is the same as no row at all.
                if (null !== $value && '' !== $value) {
                    $values[$column] = (string) $value;
                }
            }

            $this->loaded[(int) $product->getId()] = $values;
        }
    }

    /** @param \Magento\Quote\Model\Quote\Item $item */
    private static function productIdOf($item): ?int
    {
        $catalogProduct = $item->getProduct();

        return $catalogProduct ? (int) $catalogProduct->getId() : null;
    }
}
