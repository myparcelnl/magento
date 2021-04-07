<?php

namespace MyParcelNL\Magento\Model\Checkout;

use MyParcelNL\Magento\Api\PackageTypeInterface;
use MyParcelNL\Magento\Model\Quote\Checkout;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

/**
 * @since 3.0.0
 */
class PackageType implements PackageTypeInterface
{
    /**
     * @var Checkout
     */
    private $settings;

    /**
     * Checkout constructor.
     *
     * @param Checkout $settings
     */
    public function __construct(
        Checkout $settings
    ) {
        $this->settings = $settings;
    }

    /**
     *
     * @param string $countryCode
     *
     * @return string
     */
    public function getPackageType(string $countryCode): string
    {
        $carriersPath = $this->settings->get_carriers();
        $packageType  = AbstractConsignment::PACKAGE_TYPE_PACKAGE_NAME;

        foreach ($carriersPath as $carrier) {
            $packageType = $this->settings->checkPackageType($carrier, $countryCode);
        }

        return $packageType;
    }
}
