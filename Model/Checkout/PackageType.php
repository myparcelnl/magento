<?php

namespace MyParcelBE\Magento\Model\Checkout;

use MyParcelBE\Magento\Api\PackageTypeInterface;
use MyParcelBE\Magento\Model\Quote\Checkout;

/**
 * @since 3.0.0
 */
class PackageType implements PackageTypeInterface
{
    /**
     * @var Checkout
     */
    private $checkout;

    /**
     * Checkout constructor.
     *
     * @param Checkout $checkout
     */
    public function __construct(
        Checkout $checkout
    ) {
        $this->checkout = $checkout;
    }

    /**
     * @param string $carrier
     * @param string $countryCode
     *
     * @return string
     */
    public function getPackageType(string $carrier, string $countryCode): string
    {
        return $this->checkout->checkPackageType($carrier, $countryCode);
    }
}
