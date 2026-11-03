<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Reads the module's own product attributes off a list of quote items.
 *
 * A trait rather than a fifth service: the three functions are identical for every caller, they
 * carry no state of their own, and a class to hold them would be heavier than what it holds. The
 * using class declares and injects `$attributes`, so nothing here adds state.
 */
trait ReadsProductAttributes
{
    private ProductAttributes $attributes;

    /**
     * One product load for a whole cart, paid before the per-item reads rather than during them.
     *
     * @param \Magento\Quote\Model\Quote\Item[] $items
     */
    private function warmAttributes(array $items): void
    {
        $productIds = [];

        foreach ($items as $item) {
            $productId = self::productIdOf($item);

            if (null !== $productId) {
                $productIds[] = $productId;
            }
        }

        $this->attributes->warm($productIds);
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    private function attributeOf($item, string $column): ?int
    {
        $productId = self::productIdOf($item);

        if (null === $productId) {
            return null;
        }

        $value = $this->attributes->value($productId, $column);

        return null === $value ? null : (int) $value;
    }

    /**
     * A quote item whose catalogue product is gone has no attributes to read, and an order that
     * outlives its catalogue is ordinary. Null rather than a fatal.
     *
     * @param \Magento\Quote\Model\Quote\Item $item
     */
    private static function productIdOf($item): ?int
    {
        $catalogProduct = $item->getProduct();

        return $catalogProduct ? (int) $catalogProduct->getId() : null;
    }
}
