<?php

namespace MyParcelNL\Magento\Helper;

use Magento\Catalog\Model\Product;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\TrackTraceHolder;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\CustomsDeclaration;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use Magento\Catalog\Api\ProductRepositoryInterface;

class CustomsDeclarationFromOrder
{
    private const CURRENCY_EURO = 'EUR';

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
    }

    /**
     * @return \MyParcelNL\Sdk\src\Model\CustomsDeclaration
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
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

            $totalWeight += $product->getWeight();

            $customsItem = (new MyParcelCustomsItem())
                ->setDescription($this->getItemDescription($product->getName()))
                ->setAmount($item->getQtyShipped())
                ->setWeight($product->getWeight())
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
    public function getCountryOfOrigin(Product $product): string
    {
        $productCountryOfOrigin = $this->objectManager
            ->get(ProductRepositoryInterface::class)
            ->getById($product->getId())
            ->getCountryOfManufacture();

        return $productCountryOfOrigin ?? AbstractConsignment::CC_NL;
    }

    /**
     * @param $product
     *
     * @return int
     */
    public function getHsCode($product): int
    {
        return (int) ShipmentOptions::getAttributeValue(
            'catalog_product_entity_int',
            $product->getId(),
            'classification'
        );
    }

    /**
     * @param $description
     *
     * @return string
     */
    public function getItemDescription($description): string
    {
        if (strlen($description) > AbstractConsignment::DESCRIPTION_MAX_LENGTH) {
            $description = substr_replace($description, '...', AbstractConsignment::DESCRIPTION_MAX_LENGTH - 3);
        }

        return $description;
    }
}
