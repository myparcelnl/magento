<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter;

use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Sdk\Model\Fulfilment\OrderLine;

class OrderLineOptionsFromOrderAdapter extends OrderLine
{
    /**
     * @param object $magentoOrderItem
     */
    public function __construct($magentoOrderItem)
    {
        $standardizedDataArray = $this->prepareItemData($magentoOrderItem);
        parent::__construct($standardizedDataArray);
    }

    /**
     * @param object $magentoOrderItem
     *
     * @return array
     */
    protected function prepareItemData($magentoOrderItem): array
    {
        $magentoItemData = $magentoOrderItem->getData();
        $quantity        = (float) $magentoItemData['qty_ordered'];

        // price is per unit and tax_amount is per row; the SDK wants every amount per unit.
        $price = DeliveryCosts::getPriceInCents((float) $magentoItemData['price']);
        $tax   = (float) $magentoItemData['tax_amount'];
        $vat   = DeliveryCosts::getPriceInCents($quantity > 0.0 ? $tax / $quantity : $tax);

        return [
            'price'           => $price,
            'vat'             => $vat,
            'price_after_vat' => $price + $vat,
            'quantity'        => $magentoItemData['qty_ordered'],
            'product'         => $this->prepareProductData($magentoOrderItem),
        ];
    }

    /**
     * @param object $magentoOrderItem
     *
     * @return array
     */
    protected function prepareProductData($magentoOrderItem): array
    {
        $magentoItemData = $magentoOrderItem->getData();
        $magentoProduct  = $magentoOrderItem->getProduct();

        // A product deleted from the catalog still ships: the order row keeps its sku, its size is unknown.
        $size = static function (string $getter) use ($magentoProduct): int {
            return $magentoProduct ? (int) $magentoProduct->$getter() : 0;
        };

        return [
            'external_identifier' => (string) $magentoItemData['item_id'],
            'name'                => $magentoItemData['name'],
            'sku'                 => $magentoProduct ? $magentoProduct->getSku() : ($magentoItemData['sku'] ?? null),
            'height'              => $size('getHeight'),
            'length'              => $size('getLength'),
            'weight'              => $size('getWeight'),
            'width'               => $size('getWidth'),
            'description'         => $magentoItemData['description'],
        ];
    }
}
