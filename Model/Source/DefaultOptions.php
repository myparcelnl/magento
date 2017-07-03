<?php
/**
 * All functions to handle insurance
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Data;

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
        self::$order = $order;

        self::$chosenOptions = json_decode(self::$order->getData('delivery_options'), true);
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
        // Check that the customer has already chosen this option in the checkout
        if (is_array(self::$chosenOptions) &&
            key_exists('options', self::$chosenOptions) &&
            key_exists($option, self::$chosenOptions['options']) &&
            self::$chosenOptions['options'][$option] == true
        ) {
            return true;
        }

        $total = self::$order->getGrandTotal();
        $settings = self::$helper->getStandardConfig('options');

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

        return 0;
    }

    /**
     * Get package type
     *
     * @return int 1|2|3
     */
    public function getPackageType()
    {
        if ($this->isMailBox() === true) {
            return 2;
        }

        return 1;
    }

    private function isMailBox() {
        /** @todo get mailbox config */
        $mailboxActive = true;

        if ($mailboxActive !== true) {
            return false;
        }

        $country = self::$order->getShippingAddress()->getCountryId();
        if ($country != 'NL') {
            return false;
        }

        if (
            is_array(self::$chosenOptions) &&
            key_exists('time', self::$chosenOptions) &&
            is_array(self::$chosenOptions['time']) &&
            key_exists('price_comment', self::$chosenOptions['time'][0]) &&
            self::$chosenOptions['time'][0]['price_comment'] == 'mailbox'
        ) {
            return true;
        }

        /** @todo; check if mailbox fit in box */
        
        return false;
    }
}
