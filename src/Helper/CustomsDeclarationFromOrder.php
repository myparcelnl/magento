<?php

namespace MyParcelNL\Magento\Helper;

use Exception;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\CustomsItems;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\ShipmentDefsCustomsShipmentType;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Model\CustomsDeclaration;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;

/**
 * Builds the Order v1 customs declaration for the PPS fulfilment path.
 *
 * Declares the shipped quantity of each line, or the ordered quantity before a shipment, at product
 * weight and at the price paid in the order's currency. Both export paths share CustomsItems.
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
                'amount'  => (int) ($item->getQtyShipped() ?: $item->getQtyOrdered()),
                'price'   => (float) $item->getPrice(),
            ];
        }

        $productIds      = array_map(static fn(array $line): int => (int) $line['product']->getId(), $lines);
        $classifications = $productIds ? $this->items->classificationsFor($productIds) : [];
        $countries       = $productIds ? $this->items->countriesOfOriginFor($productIds) : [];
        $currency        = $this->order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO;

        foreach ($lines as $line) {
            $product   = $line['product'];
            $productId = (int) $product->getId();
            $amount    = $line['amount'];

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
                                            'amount'   => $this->items->lineValueInCents(
                                                $line['price'],
                                                $amount
                                            ),
                                            'currency' => $currency,
                                        ])
                    ->setCountry((string) ($countries[$productId] ?? ''))
                    ->setClassification($this->items->classification((string) ($classifications[$productId] ?? '')))
            );
        }

        $customsDeclaration
            ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
            ->setInvoice($this->order->getIncrementId())
            ->setWeight($totalWeight)
        ;

        return $customsDeclaration;
    }
}
