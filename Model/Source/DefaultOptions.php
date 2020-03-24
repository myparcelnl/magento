<?php
/**
 * All functions to handle insurance
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Model\Source;

use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Helper\Data;

class DefaultOptions
{
    /**
     * @var Data
     */
    static private $helper;

    /**
     * @var Order
     */
    static private $order;

    /**
     * @var array
     */
    static private $chosenOptions;

    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Order $order, Data $helper)
    {
        self::$helper = $helper;
        self::$order  = $order;

        self::$chosenOptions = json_decode(self::$order->getData(Checkout::FIELD_DELIVERY_OPTIONS), true);
    }

    /**
     * Get default of the option
     *
     * @param $option 'signature'
     *
     * @return bool
     */
    public function getDefault($option)
    {
        // Check that the customer has already chosen this option in the checkout
        if (is_array(self::$chosenOptions) &&
            key_exists('shipmentOptions', self::$chosenOptions) &&
            key_exists($option, self::$chosenOptions['shipmentOptions']) &&
            self::$chosenOptions['shipmentOptions'][$option] == true
        ) {
            return true;
        }

        $total    = self::$order->getGrandTotal();
        $settings = self::$helper->getStandardConfig('default_options');

        if (isset($settings[$option . '_active']) &&
            $settings[$option . '_active'] == '1' &&
            $total > (int) $settings[$option . '_from_price']
        ) {
            return true;
        }

        return false;
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @return int
     */
    public function getDefaultInsurance()
    {
        if ($this->getDefault('insurance_500')) {
            return 500;
        }

        return 0;
    }

    /**
     * Get package type
     *
     * @return int 1
     */
    public function getPackageType()
    {
        return 1;
    }

}
