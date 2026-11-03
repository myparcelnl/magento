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

            // A configurable product is ordered as a parent and a variant item; the variant is the line.
            if (! $product || CustomsItems::CONFIGURABLE === $item->getProductType()) {
                continue;
            }

            $parent         = $item->getParentItem();
            $productIds     = [(int) $product->getId()];
            $quantitySource = $item;

            if ($parent && CustomsItems::CONFIGURABLE === $parent->getProductType()) {
                $productIds[] = (int) $parent->getProductId();
                // Magento ships the parent and never registers qty_shipped on the variant.
                $quantitySource = $parent;
            }

            $lines[] = [
                'product'    => $product,
                'amount'     => (float) $quantitySource->getQtyShipped() ?: $quantitySource->getQtyOrdered(),
                'productIds' => $productIds,
            ];
        }

        $customsData = $lines
            ? $this->items->customsDataFor(array_column($lines, 'productIds'), (int) $this->order->getStoreId())
            : [];
        $currency    = $this->order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO;

        foreach ($lines as $index => $line) {
            $product = $line['product'];
            $amount  = (float) $line['amount'];

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
                    ->setCountry($customsData[$index]['country'])
                    // setClassification() cuts to 10 inside the SDK, so a longer code is truncated
                    // here where the v11 shipment path carries it whole. Raised with the SDK.
                    ->setClassification($this->items->classification($customsData[$index]['classification']))
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
