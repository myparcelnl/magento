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
 * Builds the customs declaration of a PPS order: each shipped order row at its weight and at the price
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
            if (! CustomsItems::isShippedRow($item)) {
                continue;
            }

            // Name and weight from the order row, as the label path does: a deleted product still ships.
            $lines[] = [
                'productId' => (int) $item->getProductId(),
                'name'      => (string) $item->getName(),
                'weight'    => (float) $item->getWeight(),
                // A zero-quantity line is one the API refuses, and a fractional quantity casts to 0.
                'amount'    => max(1, (int) ($item->getQtyShipped() ?: $item->getQtyOrdered())),
                'price'     => (float) $item->getPrice(),
            ];
        }

        $productIds      = array_column($lines, 'productId');
        $classifications = $productIds ? $this->items->classificationsFor($productIds) : [];
        $countries       = $productIds ? $this->items->countriesOfOriginFor($productIds) : [];
        $currency        = $order->getOrderCurrency()->getCode() ?? RefTypesMoney::CURRENCY_EUR;

        foreach ($lines as $line) {
            $productId = $line['productId'];
            $amount    = $line['amount'];

            $lineWeight   = $this->items->lineWeightInGrams($line['weight'], $amount);
            $totalWeight += $lineWeight;

            $customsDeclaration->addCustomsItem(
                (new MyParcelCustomsItem())
                    ->setDescription($this->items->description($line['name']))
                    ->setAmount($amount)
                    ->setWeight($lineWeight)
                    ->setItemValueArray([
                        'amount'   => $this->items->lineValueInCents($line['price'], $amount),
                        'currency' => $currency,
                    ])
                    ->setCountry((string) ($countries[$productId] ?? ''))
                    ->setClassification($this->items->classification((string) ($classifications[$productId] ?? '')))
            );
        }

        $customsDeclaration
            ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
            ->setInvoice((string) $order->getIncrementId())
            ->setWeight($totalWeight);

        return $customsDeclaration;
    }
}
