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
 * Builds the customs declaration for the Order v1 path.
 *
 * Declares what was ordered, at product weight and price, in the order's own currency. The v11
 * shipment path declares what was shipped, at item weight and price. Both share CustomsItems.
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

            try {
                $customsItem = (new MyParcelCustomsItem())
                    ->setDescription((string) $product->getName())
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
                    ->setClassification($this->items->classification($customsData[$index]['classification']))
                    ->setExternalProductReference($customsData[$index]['external_product_reference']);
            } catch (\InvalidArgumentException $e) {
                throw CustomsItems::refused((string) $product->getName(), $product->getSku(), $e);
            }

            $customsDeclaration->addCustomsItem($customsItem);
        }

        $customsDeclaration
            ->setContents(ShipmentDefsCustomsShipmentType::COMMERCIAL_GOODS)
            ->setInvoice($this->order->getIncrementId())
            ->setWeight($totalWeight)
        ;

        return $customsDeclaration;
    }
}
