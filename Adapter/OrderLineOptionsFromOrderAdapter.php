<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Adapter;

use MyParcelNL\Sdk\src\Model\Fulfilment\OrderLine;

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

        $price = (int) ($magentoItemData['price'] * 100.0);
        $vat   = (int) ($magentoItemData['tax_amount'] * 100.0);

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

        return [
            'external_identifier' => (string) $magentoItemData['item_id'],
            'name'                => $magentoItemData['name'],
            'sku'                 => $magentoProduct->getSku(),
            'height'              => (int) $magentoProduct->getHeight() ?: 0,
            'length'              => (int) $magentoProduct->getLength() ?: 0,
            'weight'              => (int) $magentoProduct->getWeight() ?: 0,
            'width'               => (int) $magentoProduct->getWidth() ?: 0,
            'description'         => $magentoItemData['description'],
        ];
    }
}
