<?php
/**
 * All functions to handle insurance
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @copyright   2010-2019 MyParcel
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Model\Source;

use BadMethodCallException;
use Magento\Sales\Model\Order;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierBpost;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class DefaultOptions
{
    // Maximum characters length of company name.
    private const COMPANY_NAME_MAX_LENGTH    = 50;
    private const INSURANCE_BELGIUM          = 'insurance_belgium_custom';
    private const INSURANCE_BELGIUM_AMOUNT   = 500;
    private const INSURANCE_EU_AMOUNT_50     = 'insurance_eu_50';
    private const INSURANCE_EU_AMOUNT_500    = 'insurance_eu_500';
    private const INSURANCE_AMOUNT_100       = 'insurance_100';
    private const INSURANCE_AMOUNT_250       = 'insurance_250';
    private const INSURANCE_AMOUNT_500       = 'insurance_500';
    private const INSURANCE_AMOUNT_CUSTOM    = 'insurance_custom';
    public const  DEFAULT_OPTION_VALUE       = 'default';

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
     * @param  Order $order
     * @param  Data  $helper
     */
    public function __construct(Order $order, Data $helper)
    {
        self::$helper = $helper;
        self::$order  = $order;
        try {
            $data = $order->getData(Checkout::FIELD_DELIVERY_OPTIONS);
            self::$chosenOptions = $data ? (array) json_decode($data, true) : [];
        } catch (BadMethodCallException $e) {
            self::$chosenOptions = [];
        }
    }

    /**
     * Get default of the option
     *
     * @param  string $option 'only_recipient'|'signature'|'return'|'large_format'
     * @param  string $carrier
     *
     * @return bool
     */
    public function hasDefault(string $option, string $carrier): bool
    {
        if (AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT === $option) {
            return $this->hasDefaultLargeFormat($carrier, $option);
        }

        // Check that the customer has already chosen this option in the checkout
        if (is_array(self::$chosenOptions) &&
            array_key_exists('shipmentOptions', self::$chosenOptions) &&
            array_key_exists($option, self::$chosenOptions['shipmentOptions']) &&
            self::$chosenOptions['shipmentOptions'][$option]
        ) {
            return true;
        }

        $total     = self::$order->getGrandTotal();
        $settings  = self::$helper->getStandardConfig($carrier, 'default_options');
        $activeKey = "{$option}_active";

        if (! isset($settings[$activeKey])) {
            return false;
        }

        $priceKey = "{$option}_from_price";

        return '1' === $settings[$activeKey]
            && (! ($settings[$priceKey] ?? false) || $total > (int) $settings[$priceKey]);
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
     * @param  string $carrier
     * @param  string $option
     *
     * @return bool
     */
    public function hasDefaultLargeFormat(string $carrier, string $option): bool
    {
        $price  = self::$order->getGrandTotal();
        $weight = self::$helper->convertToGrams(self::$order->getWeight());

        $settings  = self::$helper->getStandardConfig($carrier, 'default_options');
        $activeKey = "{$option}_active";

        if (isset($settings[$activeKey]) &&
             'weight' === $settings[$activeKey] &&
            $weight >= PackageRepository::DEFAULT_LARGE_FORMAT_WEIGHT
        ) {
            return true;
        }

        if (isset($settings[$activeKey]) &&
            'price' === $settings[$activeKey] &&
            $price >= $settings["{$option}_from_price"]
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  string $carrier
     * @param  string $option
     *
     * @return bool
     */
    public function hasDefaultOptionsWithoutPrice(string $carrier, string $option): bool
    {
        $settings = self::$helper->getStandardConfig($carrier, 'default_options');

        return '1' === ($settings[$option . '_active'] ?? null);
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @param  string $carrier
     *
     * @return int
     */
    public function getDefaultInsurance(string $carrier): int
    {
        $shippingAddress = self::$order->getShippingAddress();
        $shippingCountry = $shippingAddress ? $shippingAddress->getCountryId() : AbstractConsignment::CC_NL;

        if (AbstractConsignment::CC_NL === $shippingCountry) {
            return $this->getDefaultLocalInsurance($carrier);
        }

        if (AbstractConsignment::CC_BE === $shippingCountry) {
            return $this->getDefaultBeInsurance($carrier);
        }

        return $this->getDefaultEuInsurance($carrier);
    }

    /**
     * @param  string $carrier
     *
     * @return int
     */
    private function getDefaultEuInsurance(string $carrier): int
    {
        if ($this->hasDefault(self::INSURANCE_EU_AMOUNT_500, $carrier)) {
            return 500;
        }

        if ($this->hasDefault(self::INSURANCE_EU_AMOUNT_50, $carrier)) {
            return 50;
        }

        return 0;
    }

    /**
     * @param  string $carrier
     *
     * @return int
     */
    private function getDefaultBeInsurance(string $carrier): int
    {
        if ($this->hasDefault(self::INSURANCE_BELGIUM, $carrier)) {
            return self::$helper->getConfigValue(Data::CARRIERS_XML_PATH_MAP[$carrier] . 'default_options/insurance_belgium_custom_amount');
        }

        return $this->hasDefault(self::INSURANCE_BELGIUM, $carrier) ? self::$helper->getConfigValue(
            Data::CARRIERS_XML_PATH_MAP[$carrier] . 'default_options/insurance_belgium_custom_amount'
        ) : 0;
    }

    /**
     * @param  string $carrier
     *
     * @return int
     */
    private function getDefaultLocalInsurance(string $carrier): int
    {
        if ($this->hasDefault(self::INSURANCE_AMOUNT_CUSTOM, $carrier)) {
            return self::$helper->getConfigValue(Data::CARRIERS_XML_PATH_MAP[$carrier] . 'default_options/insurance_custom_amount');
        }

        if ($this->hasDefault(self::INSURANCE_AMOUNT_500, $carrier)) {
            return 500;
        }

        if ($this->hasDefault(self::INSURANCE_AMOUNT_250, $carrier)) {
            return 250;
        }

        if ($this->hasDefault(self::INSURANCE_AMOUNT_100, $carrier)) {
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
        return self::$helper->getCarrierConfig('digital_stamp/default_weight', 'myparcelbe_magento_postnl_settings/');
    }

    /**
     * Get package type ID as an int by default
     *
     * @return int
     */
    public function getPackageType(): int
    {
        if (self::$chosenOptions) {
            $keyIsPresent = array_key_exists('packageType', self::$chosenOptions);

            if ($keyIsPresent) {
                $packageType  = self::$chosenOptions['packageType'];

                return AbstractConsignment::PACKAGE_TYPES_NAMES_IDS_MAP[$packageType];
            }
        }

        return AbstractConsignment::PACKAGE_TYPE_PACKAGE;
    }

    /**
     * @return string
     */
    public function getCarrier(): string
    {
        if (self::$chosenOptions) {
            $keyIsPresent = array_key_exists('carrier', self::$chosenOptions);

            if ($keyIsPresent) {
                return self::$chosenOptions['carrier'];
            }
        }

        return CarrierBpost::NAME;
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

    /**
     * TODO: In the future, when multiple carriers will be available for Rest of World shipments, replace PostNL with a setting for default carrier
     *
     * @return \MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier
     * @throws \Exception
     */
    public static function getDefaultCarrier(): AbstractCarrier
    {
        return CarrierFactory::createFromClass(CarrierBpost::class);
    }
}
