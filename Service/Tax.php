<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use Magento\Tax\Model\Calculation as TaxCalculation;

class Tax
{
    public const DISPLAY_EXCLUDING_TAX               = '1';
    public const DISPLAY_INCLUDING_TAX               = '2';
    public const DISPLAY_INCLUDING_AND_EXCLUDING_TAX = '3';

    private Quote          $quote;
    private TaxCalculation $taxCalculation;
    private int            $shippingTaxClass;
    private bool           $priceIncludesTax;
    private bool           $displayIncluding;
    private array          $taxRates;

    public function __construct(Config $config, TaxCalculation $taxCalculation)
    {
        $this->taxCalculation = $taxCalculation;

        // Tax -> Tax Classes -> Tax Class for Shipping
        $this->shippingTaxClass = (int) $config->getConfigValue('tax/classes/shipping_tax_class');
        // Tax -> Calculation Settings -> Shipping Prices: are prices entered as tax-inclusive or exclusive? Defaults to inclusive
        $this->priceIncludesTax = '0' !== $config->getConfigValue('tax/calculation/shipping_includes_tax');
        // Tax -> Shopping Cart Display Settings -> Display Shipping Amount, default to display including tax
        $this->displayIncluding = self::DISPLAY_INCLUDING_TAX === $config->getConfigValue('tax/cart_display/shipping');
        /* ^ let op Magento verwacht ex btw als BOTH is gekozen todo */
    }


    /**
     * Get shipping tax options from Magento and apply them to the price.
     * Prices display including tax unless specifically set to excluding tax in Magento admin.
     * Optionally supply boolean to force excluding (false) or including (true) vat
     *
     * @param float     $price the shipping price you want altered for tax settings
     * @param Quote     $quote
     * @param bool|null $vat
     * @return float
     */
    public function shippingPrice(float $price, Quote $quote, ?bool $vat = null): float
    {
        $shippingTaxRate = $this->getShippingTaxRate($quote);
        $including       = $vat ?? $this->displayIncluding;

//        Debugging
//        $dump = $this->taxRates + [
//                'shippingTaxClass' => $this->shippingTaxClass,
//                'priceIncludesTax' => $this->priceIncludesTax,
//                'displayIncluding' => $this->displayIncluding,
//                'price'            => $price,
//            ];
//        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', 'SHIPPING TAX RATE (JOERIDEBUG): ' . var_export($dump, true) . "\n", FILE_APPEND);

        if ($this->priceIncludesTax === $including) {
            return $price;
        }

        $taxAmount = $this->taxCalculation->calcTaxAmount($price, $shippingTaxRate, $this->priceIncludesTax, false);

        if ($this->priceIncludesTax && !$including) {
            return $price - $taxAmount;
        }

        // !$pricesIncludeTax && $including
        return $price + $taxAmount;
    }

    private function getShippingTaxRate(Quote $quote)
    {
        // if the quote has changed, we need to recalculate the tax rates
        if (!isset($this->quote) || $this->quote->getId() !== $quote->getId()) {
            $this->quote = $quote;
            // getTaxRates(...) ultimately returns an array of available rates holding (int) ‘tax class id’ => (float) ‘rate as percentage’
            $this->taxRates = $this->taxCalculation->getTaxRates($quote->getBillingAddress()->toArray(), $quote->getShippingAddress()->toArray(), $quote->getCustomerTaxClassId());
        }

        return $this->taxRates[$this->shippingTaxClass] ?? 0.0;
    }

    /**
     * @param float $price
     * @param Quote $quote
     * @return float the price excluding VAT, accounting for settings in Magento admin
     */
    public function excludingVat(float $price, Quote $quote): float
    {
        return $this->shippingPrice($price, $quote, false);
    }

    public function includingVat(float $price, Quote $quote): float
    {
        return $this->shippingPrice($price, $quote, true);
    }

    public function addVatToExVatPrice(float $price, Quote $quote): float
    {
        $shippingTaxRate = $this->getShippingTaxRate($quote);
        $taxAmount       = $this->taxCalculation->calcTaxAmount($price, $shippingTaxRate, false, false);

        return $price + $taxAmount;
    }
}
