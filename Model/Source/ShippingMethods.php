<?php
/**
 * Get all Drop off days for MyParcel system settings
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;


use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Shipping\Model\Config;

class ShippingMethods implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;
    /**
     * @var Config
     */
    private $deliveryModelConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $deliveryModelConfig
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Config $deliveryModelConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->deliveryModelConfig = $deliveryModelConfig;
    }

    /**
     * Get all Drop off days
     *
     * @return array
     */
    public function toOptionArray()
    {
        $methods = $this->deliveryModelConfig->getAllCarriers();

        $aMethods = [];

        /** @var \Magento\OfflineShipping\Model\Carrier\Flatrate $method */
        foreach ($methods as $code => $method) {
            $aMethods[] = ['value' => $code, 'label' => $code];
        }

        return $aMethods;
    }
}
