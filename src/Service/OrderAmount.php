<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Api\Data\ShippingAssignmentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;

/**
 * The subtotal after discount, including tax, that the order amount conditions of the delivery costs matrix compare.
 *
 * Magento's tax total runs after the shipping rates are collected, so the items hold no tax after discount yet.
 * This asks Magento's tax service for it, with the items mapped as the tax collector maps them, so the rounding
 * is Magento's own. The shipping line is left out: Magento adds it after the items, so the item taxes are the same.
 */
class OrderAmount
{
    private TaxCalculationInterface            $taxCalculation;
    private CommonTaxCollector                 $taxCollector;
    private QuoteDetailsInterfaceFactory       $quoteDetailsFactory;
    private TaxClassKeyInterfaceFactory        $taxClassKeyFactory;
    private ShippingAssignmentInterfaceFactory $shippingAssignmentFactory;
    private TaxConfig                          $taxConfig;

    public function __construct(
        TaxCalculationInterface            $taxCalculation,
        CommonTaxCollector                 $taxCollector,
        QuoteDetailsInterfaceFactory       $quoteDetailsFactory,
        TaxClassKeyInterfaceFactory        $taxClassKeyFactory,
        ShippingAssignmentInterfaceFactory $shippingAssignmentFactory,
        TaxConfig                          $taxConfig
    ) {
        $this->taxCalculation            = $taxCalculation;
        $this->taxCollector              = $taxCollector;
        $this->quoteDetailsFactory       = $quoteDetailsFactory;
        $this->taxClassKeyFactory        = $taxClassKeyFactory;
        $this->shippingAssignmentFactory = $shippingAssignmentFactory;
        $this->taxConfig                 = $taxConfig;
    }

    /** Get the subtotal after discount, including tax, in the store view currency, without shipping. */
    public function get(Quote $quote): float
    {
        $quoteItems = $quote->getAllItems();

        if (! $quoteItems) {
            return 0.0;
        }

        $store = $quote->getStore();
        $items = $this->taxCollector->mapItems(
            $this->shippingAssignmentFactory->create()->setItems($quoteItems),
            $this->taxConfig->priceIncludesTax($store),
            false
        );

        $quoteDetails = $this->quoteDetailsFactory->create();
        $this->taxCollector->populateAddressData($quoteDetails, $quote->getShippingAddress());
        $quoteDetails->setCustomerTaxClassKey(
            $this->taxClassKeyFactory->create()
                ->setType(TaxClassKeyInterface::TYPE_ID)
                ->setValue($quote->getCustomerTaxClassId())
        );
        $quoteDetails->setItems($items);
        $quoteDetails->setCustomerId($quote->getCustomerId());

        $taxedItems = $this->taxCalculation->calculateTax($quoteDetails, (int) $store->getStoreId())->getItems();

        return max(0.0, round($this->sumProducts($items, $taxedItems), 2));
    }

    /**
     * Adds up the products as Magento's grand total does: row total - discount + tax + discount tax compensation.
     *
     * A parent priced through its children (a dynamic bundle) holds the sum of those children, so only the children
     * count. Other types, such as FPT, are no part of the subtotal.
     *
     * @param QuoteDetailsItemInterface[] $items
     * @param TaxDetailsItemInterface[]   $taxedItems keyed by item code
     */
    private function sumProducts(array $items, array $taxedItems): float
    {
        $parentCodes = [];
        foreach ($items as $item) {
            if (null !== $item->getParentCode()) {
                $parentCodes[$item->getParentCode()] = true;
            }
        }

        $amount = 0.0;
        foreach ($items as $item) {
            $taxed = $taxedItems[$item->getCode()] ?? null;

            if (null === $taxed
                || CommonTaxCollector::ITEM_TYPE_PRODUCT !== $item->getType()
                || isset($parentCodes[$item->getCode()])
            ) {
                continue;
            }

            $amount += (float) $taxed->getRowTotal()
                       - (float) $item->getDiscountAmount()
                       + (float) $taxed->getRowTax()
                       + (float) $taxed->getDiscountTaxCompensationAmount();
        }

        return $amount;
    }
}
