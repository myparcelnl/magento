<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use Magento\Tax\Model\Calculation as TaxCalculation;

class Tax
{
    public const DISPLAY_EXCLUDING_TAX = '1';
    public const DISPLAY_INCLUDING_TAX = '2';
    public const DISPLAY_INCLUDING_AND_EXCLUDING_TAX = '3';

    private Config         $config;
    private TaxCalculation $taxCalculation;

    public function __construct(Config $config, TaxCalculation $taxCalculation)
    {
        $this->config         = $config;
        $this->taxCalculation = $taxCalculation;
    }


    /**
     * Get shipping tax options from Magento and apply them to the price.
     * Prices display including tax unless specifically set to excluding tax in Magento admin.
     *
     * @param float $price the shipping price you want altered for tax settings
     * @param Quote $quote
     * @return float
     */
    public function shippingPriceForDisplay(float $price, Quote $quote): float
    {
        // Tax -> Tax Classes -> Tax Class for Shipping
        $shippingTaxClass = (int) $this->config->getConfigValue('tax/classes/shipping_tax_class');
        // Tax -> Calculation Settings -> Shipping Prices: are prices entered as tax-inclusive or exclusive? Defaults to inclusive
        $priceIncludesTax = '0' !== $this->config->getConfigValue('tax/calculation/shipping_includes_tax');
        // Tax -> Shopping Cart Display Settings -> Display Shipping Amount, default to display including tax
        $displayIncluding = self::DISPLAY_EXCLUDING_TAX !== $this->config->getConfigValue('tax/cart_display/shipping');

        // getTaxRates(...) ultimately returns an array of available rates holding (int) ‘tax class id’ => (float) ‘rate as percentage’
        $taxRates        = $this->taxCalculation->getTaxRates($quote->getBillingAddress()->toArray(), $quote->getShippingAddress()->toArray(), $quote->getCustomerTaxClassId());
        $shippingTaxRate = $taxRates[$shippingTaxClass] ?? 0.0;

        if ($priceIncludesTax === $displayIncluding) {
            return $price;
        }

        $taxAmount = $this->taxCalculation->calcTaxAmount($price, $shippingTaxRate, $priceIncludesTax);

        if ($priceIncludesTax && !$displayIncluding) {
            return $price - $taxAmount;
        }

        // !$pricesIncludeTax && $displayIncluding
        return $price + $taxAmount;
    }
}
