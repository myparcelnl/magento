<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;

class DeliveryCosts
{
    use NeedsQuoteProps;

    private Weight $weight;
    private Config $config;

    public function __construct(Weight $weight, Config $config)
    {
        $this->weight = $weight;
        $this->config = $config;
    }

    public function getBasePrice(Quote $quote): float
    {
        $carrier     = $quote->getShippingAddress()->getShippingMethod(); // todo get actual (chosen) carrier
        $countryCode = $quote->getShippingAddress()->getCountryId();
        $packageType = 'package'; // todo get actual (chosen) package type
        $weight      = $this->weight->getEmptyPackageWeightInGrams($packageType)
                       + $this->weight->getQuoteWeightInGrams($quote);

        if ($this->isFreeShippingAvailable($quote)) {
            return 0.0;
        }
        // todo implement the actual logic based on configured multi dimensional object
        return (float) $this->config->getMagentoCarrierConfig('shipping_cost');
    }

    /**
     * @param float $price
     *
     * @return int
     */
    public static function getPriceInCents(float $price): int
    {
        return (int) ($price * 100);
    }
}
