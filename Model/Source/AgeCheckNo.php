<?php
/**
 * Get only the "No" option for in the MyParcel system settings
 * This option is used with settings that are not possible because an parent option is turned off.
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <support@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Data;

/**
 * @api
 * @since 100.0.2
 */
class AgeCheckNo implements ArrayInterface
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
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Order $order, Data $helper)
    {
        self::$helper = $helper;
        self::$order = $order;
    }

    /**
     * @param $option
     *
     * @return bool
     */
    public function getDefault($option)
    {
        $settings = self::$helper->getStandardConfig('options');

        if ($settings[$option . '_active'] == '1') {
            return true;
        }

        return false;
    }

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        if ($this->getDefault('age_check')) {
            return [['value' => 0, 'label' => __('No')]];
        }

        return [['value' => 1, 'label' => __('Yes')], ['value' => 0, 'label' => __('No')]];
    }
}
