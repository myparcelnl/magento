<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Framework\ObjectManagerInterface;

/**
 * Reads the module's own `myparcel_*` product attributes for a batch of products, all in one load.
 *
 * Through the product collection, never the EAV value tables directly. Adobe Commerce keys those
 * tables on `row_id` and holds one row per scheduled update, so a hand-built query needs both an
 * entity-id mapping and a live-version window to answer correctly; the collection does both. It
 * also knows each attribute's backend type, which retires the varchar-then-int probing the raw
 * reader needed.
 *
 * One load covers every attribute: asking for eight columns costs barely more than one, and the
 * callers read several per product.
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
        'external_product_reference',
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
}
