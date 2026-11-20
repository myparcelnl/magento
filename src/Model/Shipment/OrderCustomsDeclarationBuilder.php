<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsCustomsShipmentType;
use MyParcelNL\Sdk\Model\CustomsDeclaration;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;

/**
 * Builds the customs declaration of a PPS order: each order line at product weight and at the price
 * paid, in the order's currency. The label path's counterpart is CustomsDeclarationBuilder.
 */
class OrderCustomsDeclarationBuilder
{
    private const CURRENCY_EURO = 'EUR';

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

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();

            $parent    = $item->getParentItem();
            $isVariant = $parent && CustomsItems::CONFIGURABLE === $parent->getProductType();

            // The rows a label ships, but a configurable is declared by its variant, which holds the
            // customs data. Other dummy rows, such as a bundle shipped separately, ship as their items.
            if (! $product
                || $item->getIsVirtual()
                || CustomsItems::CONFIGURABLE === $item->getProductType()
                || ($item->isDummy(true) && ! $isVariant)
            ) {
                continue;
            }

            $productIds     = [(int) $product->getId()];
            $quantitySource = $item;

            if ($isVariant) {
                $productIds[] = (int) $parent->getProductId();
                // Magento ships the parent and never registers qty_shipped on the variant.
                $quantitySource = $parent;
            }

            $lines[] = [
                'product'    => $product,
                // A zero-quantity line is one the API refuses, and a fractional quantity casts to 0.
                'amount'     => max(1, (int) ($quantitySource->getQtyShipped() ?: $quantitySource->getQtyOrdered())),
                'price'      => (float) $quantitySource->getPrice(),
                'productIds' => $productIds,
            ];
        }

        $customsData = $lines
            ? $this->items->customsDataFor(array_column($lines, 'productIds'), (int) $order->getStoreId())
            : [];
        $currency    = $order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO;

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            $amount  = $line['amount'];

            // Computed once and reused for the declaration total, which is the sum of the line
            // weights and must agree with them.
            $lineWeight   = $this->items->lineWeightInGrams((float) $product->getWeight(), $amount);
            $totalWeight += $lineWeight;

            $customsDeclaration->addCustomsItem(
                (new MyParcelCustomsItem())
                    ->setDescription($this->items->description((string) $product->getName()))
                    ->setAmount($amount)
                    ->setWeight($lineWeight)
                    ->setItemValueArray([
                        'amount'   => $this->items->lineValueInCents($line['price'], $amount),
                        'currency' => $currency,
                    ])
                    ->setCountry($customsData[$index]['country'])
                    ->setClassification($this->items->classification($customsData[$index]['classification']))
            );
        }

        $customsDeclaration
            ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
            ->setInvoice((string) $order->getIncrementId())
            ->setWeight($totalWeight);

        return $customsDeclaration;
    }
}
