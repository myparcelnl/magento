<?php

namespace MyParcelNL\Magento\Helper;

use Exception;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\Weight;
use MyParcelNL\Sdk\src\Exception\MissingFieldException;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\CustomsDeclaration;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use MyParcelNL\Sdk\src\Support\Str;

class CustomsDeclarationFromOrder
{
    private const CURRENCY_EURO = 'EUR';

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

            if (!$product) {
                continue;
            }

            $amount      = (float)$item->getQtyShipped() ? $item->getQtyShipped() : $item->getQtyOrdered();
            $totalWeight += $this->weightService->convertToGrams($product->getWeight() * $amount);
            $description = Str::limit($product->getName(), AbstractConsignment::DESCRIPTION_MAX_LENGTH);

            $customsItem = (new MyParcelCustomsItem())
                ->setDescription($description)
                ->setAmount($amount)
                ->setWeight($this->weightService->convertToGrams($product->getWeight()))
                ->setItemValueArray([
                                        'amount'   => DeliveryCosts::getPriceInCents($product->getPrice()),
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
     * @param Product $product
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
     * @param Product $product
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
