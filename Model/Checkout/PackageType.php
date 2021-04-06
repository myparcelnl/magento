<?php
/**
 * Get delivery prices and settings
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v2.0.0
 */

namespace MyParcelNL\Magento\Model\Checkout;

use MyParcelNL\Magento\Api\PackageTypeInterface;
use MyParcelNL\Magento\Model\Quote\Checkout;

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
        $packageType  = 'package';
//        $countryCode  = 'NL';

        foreach ($carriersPath as $carrier) {
            $packageType = $this->settings->checkPackageType($carrier, $countryCode);
        }

        return $packageType;
    }
}
