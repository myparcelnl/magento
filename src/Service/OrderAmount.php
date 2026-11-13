<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Api\Data\ShippingAssignmentInterfaceFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\Data\TaxDetailsItemInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Tax\Model\Sales\Total\Quote\CommonTaxCollector;
use MyParcelNL\Magento\Facade\Logger;

/**
 * The subtotal after discount, including tax, that the order amount conditions of the delivery costs matrix compare.
 *
 * Inside a collect, Magento's tax total runs after the shipping rates, so the items hold no tax after discount yet.
 * This asks Magento's tax service for it, with the items mapped as the tax collector maps them, so the rounding
 * is Magento's own. Outside a collect, mapping would read the saved net price as gross and write it back to the
 * item, which corrupts the next collect, so the saved totals of the last collect are summed instead.
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

        if (! self::isMappedForTax($quoteItems)) {
            return round(self::sumSavedTotals($quoteItems), 2);
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

        // the interface promises no keys and may return null, so key the result by code as CommonTaxCollector does
        $taxedItems = [];
        foreach ($this->taxCalculation->calculateTax($quoteDetails, (int) $store->getStoreId())->getItems() ?? [] as $taxed) {
            $taxedItems[$taxed->getCode()] = $taxed;
        }

        return round($this->sumProducts($items, $taxedItems), 2);
    }

    /**
     * Whether the tax subtotal collector mapped the items in this request, which it does before the shipping rates.
     * Only the items that mapItems() maps count: a child of a configurable never gets a tax calculation price.
     *
     * @param AbstractItem[] $quoteItems
     */
    private static function isMappedForTax(array $quoteItems): bool
    {
        foreach ($quoteItems as $item) {
            $parent = $item->getParentItem();

            if (($parent && ! $parent->isChildrenCalculated()) || null !== $item->getTaxCalculationPrice()) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Adds up the totals Magento saved on the items at its last collect, the same sum as sumProducts().
     * A parent priced through its children counts through those children, any other child through its parent.
     *
     * @param AbstractItem[] $quoteItems
     */
    private static function sumSavedTotals(array $quoteItems): float
    {
        $amount = 0.0;
        foreach ($quoteItems as $item) {
            $parent             = $item->getParentItem();
            $childOfPlainParent = $parent && ! $parent->isChildrenCalculated();
            $parentOfCalculated = $item->getHasChildren() && $item->isChildrenCalculated();

            if ($childOfPlainParent || $parentOfCalculated) {
                continue;
            }

            $amount += (float) $item->getRowTotal()
                       - (float) $item->getDiscountAmount()
                       + (float) $item->getTaxAmount()
                       + (float) $item->getDiscountTaxCompensationAmount();
        }

        return $amount;
    }

    /**
     * Adds up the products as Magento's grand total does: row total - discount + tax + discount tax compensation.
     *
     * A parent priced through its children (a dynamic bundle) holds the sum of those children, so only the children
     * count. Other types, such as FPT, are no part of the subtotal. A product the tax service did not return, as a
     * third-party implementation may do, is left out and logged: a thrown warning would break the checkout.
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

        $amount  = 0.0;
        $missing = [];
        foreach ($items as $item) {
            if (CommonTaxCollector::ITEM_TYPE_PRODUCT !== $item->getType()
                || isset($parentCodes[$item->getCode()])
            ) {
                continue;
            }

            $taxed = $taxedItems[$item->getCode()] ?? null;

            if (null === $taxed) {
                $missing[] = $item->getCode();
                continue;
            }

            $amount += (float) $taxed->getRowTotal()
                       - (float) $item->getDiscountAmount()
                       + (float) $taxed->getRowTax()
                       + (float) $taxed->getDiscountTaxCompensationAmount();
        }

        if ($missing) {
            Logger::notice(
                'The tax service returned no item for these codes, the order amount leaves them out',
                ['codes' => $missing]
            );
        }

        return $amount;
    }
}
