<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\Export;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesMoney;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsCustomsShipmentType;
use MyParcelNL\Sdk\Model\CustomsDeclaration;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;

/**
 * Builds the customs declaration of an Order v1 order: each shipped order row at its weight and at the price
 * paid, in the order's currency. The label path's counterpart is CustomsDeclarationBuilder.
 */
class OrderCustomsDeclarationBuilder
{
    private CustomsItems $items;

    public function __construct(ObjectManagerInterface $objectManager, Config $config, Weight $weight)
    {
        $this->items = new CustomsItems($objectManager, $config, $weight);
    }

    public function build(Order $order): CustomsDeclaration
    {
        $customsDeclaration = new CustomsDeclaration();
        $totalWeight        = 0;
        $lines              = [];

        /** @var \Magento\Sales\Model\Order\Item $item getItems() declares the interface */
        foreach ($order->getItems() as $item) {
            $parent    = $item->getParentItem();
            $isVariant = $parent && CustomsItems::CONFIGURABLE === $parent->getProductType();

            // The rows a label ships, but a configurable is declared by its variant, which holds the
            // customs data. Other dummy rows, such as a bundle shipped separately, ship as their items.
            if ($item->getIsVirtual()
                || CustomsItems::CONFIGURABLE === $item->getProductType()
                || ($item->isDummy(true) && ! $isVariant)
            ) {
                continue;
            }

            // The order row's product id, name and weight: a product deleted from the catalog still ships.
            $productIds     = [(int) $item->getProductId()];
            $quantitySource = $item;

            if ($isVariant) {
                $productIds[] = (int) $parent->getProductId();
                // Magento ships the parent and never registers qty_shipped on the variant.
                $quantitySource = $parent;
            }

            $lines[] = [
                'name'       => (string) $item->getName(),
                'weight'     => (float) $item->getWeight(),
                // A zero-quantity line is one the API refuses, and a fractional quantity casts to 0.
                // The database gives "0.0000", which is truthy: cast before the fallback.
                'amount'     => max(1, (int) ((float) $quantitySource->getQtyShipped() ?: (float) $quantitySource->getQtyOrdered())),
                'price'      => (float) $quantitySource->getPrice(),
                'productIds' => $productIds,
            ];
        }

        $customsData = $lines
            ? $this->items->customsDataFor(array_column($lines, 'productIds'), (int) $order->getStoreId())
            : [];
        $currency    = $order->getOrderCurrency()->getCode() ?? RefTypesMoney::CURRENCY_EUR;

        foreach ($lines as $index => $line) {
            $amount = $line['amount'];

            // Computed once and reused for the declaration total, which is the sum of the line
            // weights and must agree with them.
            $lineWeight   = $this->items->lineWeightInGrams($line['weight'], $amount);
            $totalWeight += $lineWeight;

            $customsItem = (new MyParcelCustomsItem())
                ->setDescription($this->items->description($line['name']))
                ->setAmount($amount)
                ->setWeight($lineWeight)
                ->setItemValueArray([
                    'amount'   => $this->items->lineValueInCents($line['price'], $amount),
                    'currency' => $currency,
                ])
                ->setCountry($customsData[$index]['country'])
                ->setClassification($this->items->classification($customsData[$index]['classification']));

            if ('' !== $customsData[$index]['external_product_reference']) {
                $customsItem->setExternalProductReference($customsData[$index]['external_product_reference']);
            }

            $customsDeclaration->addCustomsItem($customsItem);
        }

        $customsDeclaration
            ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
            ->setInvoice((string) $order->getIncrementId())
            ->setWeight($totalWeight);

        return $customsDeclaration;
    }
}
