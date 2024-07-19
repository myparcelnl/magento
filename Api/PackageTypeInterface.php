<?php

namespace MyParcelBE\Magento\Api;

/**
 * Get package type
 */
interface PackageTypeInterface
{
    /**
     * Return packageType
     *
     * @param string $carrier
     * @param string $countryCode
     *
     * @return string
     */
    public function getPackageType(string $carrier, string $countryCode): string;
}
