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
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class DefaultOptions
{
    // Maximum characters length of company name.
    private const COMPANY_NAME_MAX_LENGTH = 50;

    private const INSURANCE_AMOUNT_BELGIUM = 500;

    /**
     * @var Data
     */
    private static $helper;

    /**
     * @var Order
     */
    private static $order;

    /**
     * @var array
     */
    private static $chosenOptions;

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
    public function getDefault($option): bool
    {
        // Check that the customer has already chosen this option in the checkout
        if (is_array(self::$chosenOptions) &&
            array_key_exists('shipmentOptions', self::$chosenOptions) &&
            array_key_exists($option, self::$chosenOptions['shipmentOptions']) &&
            self::$chosenOptions['shipmentOptions'][$option]
        ) {
            return true;
        }

        $total    = self::$order->getGrandTotal();
        $settings = self::$helper->getStandardConfig('default_options');

        if (! isset($settings[$option . '_active'])) {
            return false;
        }

        return '1' === $settings[$option . '_active']
            && (! ($settings[$option . '_from_price'] ?? false) || $total > (int) $settings[$option . '_from_price']);
    }

    /**
     * @param string|null $company
     *
     * @return string|null
     */
    public function getMaxCompanyName(?string $company): ?string
    {
        if ($company !== null && (strlen($company) >= self::COMPANY_NAME_MAX_LENGTH)) {
            $company = substr($company, 0, 47) . '...';
        }

        return $company;
    }

    /**
     * Get default value of options without price check
     *
     * @param string $option
     *
     * @return bool
     */
    public function getDefaultLargeFormat(string $option): bool
    {
        $price  = self::$order->getGrandTotal();
        $weight = self::$order->getWeight();

        $settings = self::$helper->getStandardConfig('default_options');
        if (isset($settings[$option . '_active']) &&
             'weight' === $settings[$option . '_active'] &&
            $weight >= PackageRepository::DEFAULT_LARGE_FORMAT_WEIGHT
        ) {
            return true;
        }

        if (isset($settings[$option . '_active']) &&
            'price' === $settings[$option . '_active'] &&
            $price >= $settings[$option . '_from_price']
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
    public function getDefaultOptionsWithoutPrice(string $option): bool
    {
        $settings = self::$helper->getStandardConfig('default_options');

        return '1' === $settings[$option . '_active'];
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @return int
     */
    public function getDefaultInsurance(): int
    {
        $shippingAddress = self::$order->getShippingAddress();

        if ($shippingAddress && AbstractConsignment::CC_BE === $shippingAddress->getCountryId()) {
            return $this->getDefault('insurance_belgium') ? self::INSURANCE_AMOUNT_BELGIUM : 0;
        }

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
     * @return string
     */
    public function getDigitalStampDefaultWeight(): string
    {
        return self::$helper->getCarrierConfig('digital_stamp/default_weight', 'myparcelnl_magento_postnl_settings/');
    }

    /**
     * Get package type ID as an int by default
     *
     * @return int
     */
    public function getPackageType(): int
    {
        $country      = self::$order->getShippingAddress()->getCountryId();
        $isNL         = AbstractConsignment::CC_NL === $country;

        if (self::$chosenOptions) {
            $keyIsPresent = array_key_exists('packageType', self::$chosenOptions);

            if ($isNL && $keyIsPresent) {
                $packageType  = self::$chosenOptions['packageType'];

                return AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType];
            }
        }

        return AbstractConsignment::PACKAGE_TYPE_PACKAGE;
    }

    /**
     * Get package type name as a string by default
     *
     * @return string
     */
    public function getPackageTypeName(): string
    {
        $packageTypesMap = array_flip(AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP);
        return $packageTypesMap[$this->getPackageType()];
    }
}
