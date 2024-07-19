<?php
/**
 * ${CARET}
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Sales;


interface PackageInterface
{
    /**
     * @return float
     */
    public function getWeight();
    /**
     * @param $weight
     */
    public function setWeight(float $weight);
    /**
     * @param float $weight
     */
    public function addWeight(float $weight);

    /**
     * @param bool $all_products_fit
     */
    public function setAllProductsFit(bool $all_products_fit);

    /**
     * package = 1
     *
     * @return int
     */
    public function getPackageType();
    /**
     * package = 1
     *
     * @param int $package_type
     */
    public function setPackageType(int $package_type);
    /**
     * @return string
     */
    public function getCurrentCountry();
    /**
     * @param string $current_country
     * @return Package
     */
    public function setCurrentCountry(string $current_country);
}
