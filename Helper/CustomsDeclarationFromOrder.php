<?php

namespace MyParcelBE\Magento\Helper;

use Magento\Catalog\Model\Product;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\CustomsDeclaration;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use Magento\Catalog\Api\ProductRepositoryInterface;
use MyParcelNL\Sdk\src\Support\Str;
use MyParcelBE\Magento\Helper\Data;

class CustomsDeclarationFromOrder
{
    private const CURRENCY_EURO = 'EUR';

    /**
     * @var mixed
     */
    private $helper;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @param  \Magento\Sales\Model\Order                $order
     * @param  \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(Order $order, ObjectManagerInterface $objectManager)
    {
        $this->order         = $order;
        $this->objectManager = $objectManager;
        $this->helper        = $this->objectManager->get(Data::class);
    }

    /**
     * @return \MyParcelNL\Sdk\src\Model\CustomsDeclaration
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \Exception
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
            $totalWeight += $this->helper->convertToGrams($product->getWeight() * $amount);
            $description = Str::limit($product->getName(), AbstractConsignment::DESCRIPTION_MAX_LENGTH);

            $customsItem = (new MyParcelCustomsItem())
                ->setDescription($description)
                ->setAmount($amount)
                ->setWeight($this->helper->convertToGrams($product->getWeight()))
                ->setItemValueArray([
                    'amount'   => TrackTraceHolder::getCentsByPrice($product->getPrice()),
                    'currency' => $this->order->getOrderCurrency()->getCode() ?? self::CURRENCY_EURO,
                ])
                ->setCountry($this->getCountryOfOrigin($product))
                ->setClassification($this->getHsCode($product));

            $customsDeclaration->addCustomsItem($customsItem);
        }

        $customsDeclaration
            ->setContents(AbstractConsignment::PACKAGE_CONTENTS_COMMERCIAL_GOODS)
            ->setInvoice($this->order->getIncrementId())
            ->setWeight($totalWeight);

        return $customsDeclaration;
    }

    /**
     * @param  \Magento\Catalog\Model\Product $product
     *
     * @return string
     */
    private function getCountryOfOrigin(Product $product): string
    {
        $productCountryOfOrigin = $this->objectManager
            ->get(ProductRepositoryInterface::class)
            ->getById($product->getId())
            ->getCountryOfManufacture();

        return $productCountryOfOrigin ?? AbstractConsignment::CC_NL;
    }

    /**
     * @param  \Magento\Catalog\Model\Product $product
     *
     * @return int
     */
    private function getHsCode(Product $product): int
    {
        return (int) ShipmentOptions::getAttributeValue(
            'catalog_product_entity_int',
            $product->getId(),
            'classification'
        );
    }
}
