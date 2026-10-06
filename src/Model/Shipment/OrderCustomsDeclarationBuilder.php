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

            // The rows a label ships: getQtyToShip() is 0 for a dummy row, such as the child of a
            // configurable or the parent of a bundle shipped separately.
            if (! $product || $item->isDummy(true) || $item->getIsVirtual()) {
                continue;
            }

            $lines[] = [
                'product' => $product,
                // A zero-quantity line is one the API refuses, and a fractional quantity casts to 0.
                'amount'  => max(1, (int) ($item->getQtyShipped() ?: $item->getQtyOrdered())),
                'price'   => (float) $item->getPrice(),
            ];
        }

        $productIds      = array_map(static fn(array $line): int => (int) $line['product']->getId(), $lines);
        $classifications = $productIds ? $this->items->classificationsFor($productIds) : [];
        $countries       = $productIds ? $this->items->countriesOfOriginFor($productIds, (int) $order->getStoreId()) : [];
        $currency        = $order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO;

        foreach ($lines as $line) {
            $product   = $line['product'];
            $productId = (int) $product->getId();
            $amount    = $line['amount'];

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
