<?php

namespace MyParcelNL\Magento\Api;

/**
 * Get package type
 */
interface PackageTypeInterface
{
    /**
     * Return packageType
     *
     * @param string $countryCode
     *
     * @return string
     */
    public function getPackageType(string $countryCode): string;
}
