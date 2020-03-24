<?php
/**
 * This class contain all methods to check the type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe/magento
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Sales;


use Magento\Framework\App\ObjectManager;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Quote\Api\Data\EstimateAddressInterfaceFactory;
use MyParcelBE\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;
use Psr\Log\LoggerInterface;

class Package extends Data implements PackageInterface
{
    const PACKAGE_TYPE_NORMAL = 1;

    /**
     * @var int
     */
    private $weight = 0;

    /**
     * @var bool
     */
    private $all_products_fit = true;

    /**
     * @var string
     */
    private $current_country = 'BE';

    /**
     * @var int
     */
    private $package_type = null;

    /**
     * @return int
     */
    public function getWeight()
    {
        return $this->weight;
    }

    /**
     * @param $weight
     */
    public function setWeight($weight)
    {
        $this->weight = (int)$weight;
    }

    /**
     * @param int $weight
     */
    public function addWeight($weight)
    {
        $this->weight += (int)$weight;
    }

    /**
     * @return bool
     */
    public function isAllProductsFit()
    {
        return $this->all_products_fit;
    }

    /**
     * @param bool $all_products_fit
     */
    public function setAllProductsFit($all_products_fit)
    {
        if ($all_products_fit === false) {
            $this->all_products_fit = $all_products_fit;
        }
    }

    /**
     * package = 1
     *
     * @return int
     */
    public function getPackageType()
    {
        return $this->package_type;
    }

    /**
     * package = 1
     *
     * @param int $package_type
     */
    public function setPackageType($package_type)
    {
        $this->package_type = $package_type;
    }

    /**
     * @return string
     */
    public function getCurrentCountry()
    {
        return $this->current_country;
    }

    /**
     * @param string $current_country
     * @return Package
     */
    public function setCurrentCountry($current_country)
    {
        $this->current_country = $current_country;

        return $this;
    }
}