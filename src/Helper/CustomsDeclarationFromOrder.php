<?php

namespace MyParcelNL\Magento\Helper;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Model\CustomsDeclaration;
use MyParcelNL\Sdk\Model\MyParcelCustomsItem;
use MyParcelNL\Sdk\Support\Str;

class CustomsDeclarationFromOrder
{
    private const CURRENCY_EURO = 'EUR';

    // beta.31's MyParcelCustomsItem hard-codes 50; kept here so the truncation stays visible.
    private const DESCRIPTION_MAX_LENGTH    = 50;
    private const CONTENTS_COMMERCIAL_GOODS = 1;

    /**
     * @var mixed
     */
    private $helper;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * @var Order
     */
    private $order;

    /**
     * @var Weight
     */
    private $weightService;

    /**
     * @param Order $order
     */
    public function __construct(Order $order)
    {
        $objectManager       = ObjectManager::getInstance();
        $this->order         = $order;
        $this->objectManager = $objectManager;
        $this->weightService = $objectManager->get(Weight::class);
    }

    /**
     * @return CustomsDeclaration
     * @throws MissingFieldException
     * @throws Exception
     */
    public function createCustomsDeclaration(): CustomsDeclaration
    {
        $customsDeclaration = new CustomsDeclaration();
        $totalWeight        = 0;

        foreach ($this->order->getItems() as $item) {
            $product = $item->getProduct();

            if (! $product) {
                continue;
            }

            $amount      = (float) $item->getQtyShipped() ? $item->getQtyShipped() : $item->getQtyOrdered();
            $description = Str::limit($product->getName(), self::DESCRIPTION_MAX_LENGTH);

            // A customs item carries the line's weight and the line's value while amount carries the
            // count, so both are multiplied here. Computed once and reused for the declaration
            // total, which is the sum of the line weights and must agree with them.
            $lineWeight   = $this->weightService->convertToGrams($product->getWeight() * $amount);
            $lineValue    = (int) round(DeliveryCosts::getPriceInCents($product->getPrice()) * $amount);
            $totalWeight += $lineWeight;

            $customsItem = (new MyParcelCustomsItem())
                ->setDescription($description)
                ->setAmount($amount)
                ->setWeight($lineWeight)
                ->setItemValueArray([
                                        'amount'   => $lineValue,
                                        'currency' => $this->order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO,
                                    ])
                ->setCountry($this->getCountryOfOrigin($product))
                ->setClassification($this->getHsCode($product))
            ;

            $customsDeclaration->addCustomsItem($customsItem);
        }

        $customsDeclaration
            ->setContents(self::CONTENTS_COMMERCIAL_GOODS)
            ->setInvoice($this->order->getIncrementId())
            ->setWeight($totalWeight)
        ;

        return $customsDeclaration;
    }

    /**
     * @param Product $product
     *
     * @return string
     */
    private function getCountryOfOrigin(Product $product): string
    {
        $productCountryOfOrigin = $this->objectManager
            ->get(ProductRepositoryInterface::class)
            ->getById($product->getId())
            ->getCountryOfManufacture()
        ;

        return $productCountryOfOrigin ?? CountryCode::CC_NL;
    }

    /**
     * An HS code is a string — digits and dots, leading zeros significant.
     *
     * MyParcelCustomsItem::setClassification() truncates at 10 inside the SDK, so a longer code is
     * cut on this path where the v11 shipment path carries it whole. Raised with the SDK rather than
     * worked around here.
     */
    private function getHsCode(Product $product): string
    {
        return (string) ShipmentOptionsResolver::getAttributeValue(
            'catalog_product_entity_varchar',
            $product->getId(),
            'classification'
        );
    }
}
