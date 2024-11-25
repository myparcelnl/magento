<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Service\Weight;

class DeliveryCosts
{
    private Weight $weightService;

    public function __construct(Weight $weightService)
    {
        $this->weightService = $weightService;
    }

    public function getBasePrice(Quote $quote): float
    {
        $carrier     = $quote->getShippingAddress()->getShippingMethod(); // todo get actual (chosen) carrier
        $countryCode = $quote->getShippingAddress()->getCountryId();
        $packageType = 'package'; // todo get actual (chosen) package type
        $weight      = $this->weightService->getEmptyPackageWeightInGrams($packageType)
            + $this->weightService->getQuoteWeightInGrams($quote);

        // todo implement the actual logic based on configured multi dimensional object
        return 5;
    }

    /**
     * @param float $price
     *
     * @return int
     */
    public function getPriceInCents(float $price): int
    {
        return (int)($price * 100);
    }
}
