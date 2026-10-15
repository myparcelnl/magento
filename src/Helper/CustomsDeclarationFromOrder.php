<?php

namespace MyParcelNL\Magento\Helper;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\CustomsItems;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Model\CustomsDeclaration;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;

/**
 * Builds the Order v1 customs declaration for the PPS fulfilment path.
 *
 * Declares what was ordered, at product weight and price, in the order's own currency — where the
 * v11 shipment path declares what was shipped, at item weight and price. The lookups and the line
 * arithmetic are shared through CustomsItems.
 */
class CustomsDeclarationFromOrder
{
    private const CURRENCY_EURO = 'EUR';

    /** @var Order */
    private $order;

    private CustomsItems $items;

    public function __construct(Order $order)
    {
        $objectManager = ObjectManager::getInstance();
        $this->order   = $order;
        $this->items   = new CustomsItems(
            $objectManager,
            $objectManager->get(Config::class),
            $objectManager->get(Weight::class)
        );
    }

    /**
     * @throws MissingFieldException
     * @throws Exception
     */
    public function createCustomsDeclaration(): CustomsDeclaration
    {
        $customsDeclaration = new CustomsDeclaration();
        $totalWeight        = 0;
        $lines              = [];

        foreach ($this->order->getItems() as $item) {
            $product = $item->getProduct();

            if (! $product) {
                continue;
            }

            $lines[] = [
                'product' => $product,
                'amount'  => (float) $item->getQtyShipped() ?: $item->getQtyOrdered(),
            ];
        }

        $productIds      = array_map(static fn(array $line): int => (int) $line['product']->getId(), $lines);
        $classifications = $productIds ? $this->items->classificationsFor($productIds) : [];
        $countries       = $productIds ? $this->items->countriesOfOriginFor($productIds) : [];
        $currency        = $this->order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO;

        foreach ($lines as $line) {
            $product   = $line['product'];
            $productId = (int) $product->getId();
            $amount    = (float) $line['amount'];

            // Computed once and reused for the declaration total, which is the sum of the line
            // weights and must agree with them.
            $lineWeight   = $this->items->lineWeightInGrams((float) $product->getWeight(), $amount);
            $totalWeight += $lineWeight;

            $customsDeclaration->addCustomsItem(
                (new MyParcelCustomsItem())
                    ->setDescription($this->items->description((string) $product->getName()))
                    ->setAmount($line['amount'])
                    ->setWeight($lineWeight)
                    ->setItemValueArray([
                                            'amount'   => $this->items->lineValueInCents(
                                                (float) $product->getPrice(),
                                                $amount
                                            ),
                                            'currency' => $currency,
                                        ])
                    ->setCountry((string) ($countries[$productId] ?? ''))
                    // setClassification() cuts to 10 inside the SDK, so a longer code is truncated
                    // here where the v11 shipment path carries it whole. Raised with the SDK.
                    ->setClassification($this->items->classification((string) ($classifications[$productId] ?? '')))
            );
        }

        $customsDeclaration
            ->setContents(CustomsItems::CONTENTS_COMMERCIAL_GOODS)
            ->setInvoice($this->order->getIncrementId())
            ->setWeight($totalWeight)
        ;

        return $customsDeclaration;
    }
}
