<?php
/**
 * All functions to handle insurance
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


use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Data;

class DefaultOptions
{
    /**
     * @var Data
     */
    static protected $_helper;

    /**
     * @var Order
     */
    static private $_order;

    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Order $order, Data $helper)
    {
        self::$_helper = $helper;
        self::$_order = $order;
    }

    /**
     * Get default of the option
     *
     * @param $option 'only_recipient'|'signature'|'return'|'large_format'
     *
     * @return bool
     */
    public function getDefault($option)
    {
        $total = self::$_order->getGrandTotal();
        $settings = self::$_helper->getStandardConfig('options');

        if ($settings[$option . '_active'] == '1') {
            if ($total > (int)$settings[$option . '_from_price']) {
                return true;
            } else {
                return false;
            }
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
        if ($this->getDefault('insurance_250')) {
            return 250;
        }
        if ($this->getDefault('insurance_50')) {
            return 50;
        }
    }
}