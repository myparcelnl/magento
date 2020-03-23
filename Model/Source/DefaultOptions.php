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

use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Package;

class DefaultOptions
{
    // Maximum characters length of company name.
    const COMPANY_NAME_MAX_LENGTH = 50;

    /**
     * @var Data
     */
    static private $helper;

    /**
     * @var \Magento\Sales\Model\Order|\Magento\Quote\Model\Quote
     */
    static private $order;

    /**
     * @var array
     */
    static private $chosenOptions;

    /**
     * Insurance constructor.
     *
     * @param \Magento\Sales\Model\Order|\Magento\Quote\Model\Quote $order
     * @param Data $helper
     */
    public function __construct($order, Data $helper)
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

        if ($settings[$option . '_active'] == '1' &&
            (!$settings[$option . '_from_price'] || $total > (int)$settings[$option . '_from_price'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param string|null $address
     *
     * @return string|null
     */
    public function getMaxCompanyName(?string $address): ?string
    {
        if (strlen((string) $address) >= self::COMPANY_NAME_MAX_LENGTH) {
            $address = substr($address, 0, 47) . '...';
        }

        return $address;
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
     * Get default of digital stamp weight
     *
     * @return bool
     */
    public function getDigitalStampWeight()
    {
        return self::$helper->getCheckoutConfig('digital_stamp/default_weight');
    }

    /**
     * Get package type
     *
     * @return int 1|2|3|4
     */
    public function getPackageType()
    {
        if ($this->isDigitalStampOrMailbox('mailbox') === true) {
            return Package::PACKAGE_TYPE_MAILBOX;
        }

        if ($this->isDigitalStampOrMailbox('digital_stamp') === true) {
            return Package::PACKAGE_TYPE_DIGITAL_STAMP;
        }

        return Package::PACKAGE_TYPE_NORMAL;
    }

    private function isDigitalStampOrMailbox($option) {

        $country = self::$order->getShippingAddress()->getCountryId();
        if ($country != 'NL') {
            return false;
        }

        if (
            is_array(self::$chosenOptions) &&
            key_exists('time', self::$chosenOptions) &&
            is_array(self::$chosenOptions['time']) &&
            key_exists('price_comment', self::$chosenOptions['time'][0]) &&
            self::$chosenOptions['time'][0]['price_comment'] == $option
        ) {
            return true;
        }

        return false;
    }
}
