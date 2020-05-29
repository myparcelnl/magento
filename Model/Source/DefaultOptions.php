<?php
/**
 * All functions to handle insurance
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Package;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

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
     * @param $option 'only_recipient'|'signature'|'return'|'large_format'
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
     * @param string $option
     *
     * @return bool
     */
    public function getDefaultLargeFormat(string $option): bool
    {
        $price    = self::$order->getGrandTotal();
        $weight   = self::$order->getWeight();

        $settings = self::$helper->getStandardConfig('default_options');
        if (isset($settings[$option . '_active']) &&
            $settings[$option . '_active'] == 'weight' &&
            $weight >= PackageRepository::DEFAULT_LARGE_FORMAT_WEIGHT
        ) {
            return true;
        }

        if (isset($settings[$option . '_active']) &&
            $settings[$option . '_active'] == 'price' &&
            $price >= $settings[$option . '_from_price']
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

        if ($this->getDefault('insurance_250')) {
            return 250;
        }

        if ($this->getDefault('insurance_100')) {
            return 100;
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
//        if ($this->isDigitalStampOrMailbox('mailbox') === true) {
//            return Package::PACKAGE_TYPE_MAILBOX;
//        }
//
//        if ($this->isDigitalStampOrMailbox('digital_stamp') === true) {
//            return Package::PACKAGE_TYPE_DIGITAL_STAMP;
//        }

        return Package::PACKAGE_TYPE_NORMAL;
    }

}
