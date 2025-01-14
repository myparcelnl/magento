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

namespace MyParcelNL\Magento\Model\Source;

use Exception;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class DefaultOptions
{
    // Maximum characters length of company name.
    private const  COMPANY_NAME_MAX_LENGTH = 50;
    /** @deprecated */
    private const INSURANCE_BELGIUM = 'insurance_belgium_custom';
    /** @deprecated */
    private const INSURANCE_EU_AMOUNT_50 = 'insurance_eu_50';
    /** @deprecated */
    private const INSURANCE_EU_AMOUNT_500 = 'insurance_eu_500';
    /** @deprecated */
    private const INSURANCE_AMOUNT_100 = 'insurance_100';
    /** @deprecated */
    private const INSURANCE_AMOUNT_250 = 'insurance_250';
    /** @deprecated */
    private const INSURANCE_AMOUNT_500 = 'insurance_500';
    /** @deprecated */
    private const INSURANCE_AMOUNT_CUSTOM = 'insurance_custom';

    private const INSURANCE_FROM_PRICE     = 'insurance_from_price';
    private const INSURANCE_LOCAL_AMOUNT   = 'insurance_local_amount';
    private const INSURANCE_BELGIUM_AMOUNT = 'insurance_belgium_amount';
    private const INSURANCE_EU_AMOUNT      = 'insurance_eu_amount';
    private const INSURANCE_ROW_AMOUNT     = 'insurance_row_amount';
    private const INSURANCE_PERCENTAGE     = 'insurance_percentage';
    public const  DEFAULT_OPTION_VALUE     = 'default';

    /**
     * @var Data
     */
    private $helper;

    /**
     * @var Order
     */
    private $order;

    /**
     * @var array
     */
    private $chosenOptions;

    /**
     * Insurance constructor.
     *
     * @param \Magento\Sales\Model\Order      $order
     * @param \MyParcelNL\Magento\Helper\Data $helper
     */
    public function __construct(Order $order, Data $helper)
    {
        $this->helper = $helper;
        $this->order  = $order;
        try {
            $this->chosenOptions = DeliveryOptionsAdapterFactory::create(
                (array) json_decode($order->getData(Checkout::FIELD_DELIVERY_OPTIONS), true)
            )->toArray();
        } catch (Exception $e) {
            $this->chosenOptions = [];
        }
    }

    /**
     * Get default of the option
     *
     * @param string $option 'only_recipient'|'signature'|'collect'|'receipt_code'|'return'|'large_format'
     * @param string $carrier
     *
     * @return bool
     */
    public function hasOptionSet(string $option, string $carrier): bool
    {
        if (AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT === $option) {
            return $this->hasDefaultLargeFormat($carrier, $option);
        }

        // Check that the customer has already chosen this option in the checkout
        if (is_array($this->chosenOptions) &&
            array_key_exists('shipmentOptions', $this->chosenOptions) &&
            array_key_exists($option, $this->chosenOptions['shipmentOptions']) &&
            $this->chosenOptions['shipmentOptions'][$option]
        ) {
            return true;
        }

        return $this->hasDefaultOption($carrier, $option);
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
     * @param string $carrier
     * @param string $option
     *
     * @return bool
     */
    public function hasDefaultLargeFormat(string $carrier, string $option): bool
    {
        $price  = $this->order->getGrandTotal();

        $settings  = $this->helper->getStandardConfig($carrier, 'default_options');
        $activeKey = "{$option}_active";

        return isset($settings[$activeKey]) &&
            'price' === $settings[$activeKey] &&
            $price >= $settings["{$option}_from_price"];
    }

    /**
     * @param string $carrier
     * @param string $option
     *
     * @return bool
     */
    public function hasDefaultOption(string $carrier, string $option): bool
    {
        $settings = $this->helper->getStandardConfig($carrier, 'default_options');
        if ('1' !== ($settings[$option . '_active'] ?? null)) {
            return false;
        }

        $fromPrice = $settings[$option . '_from_price'] ?? 0;
        $orderAmount = $this->order->getGrandTotal() ?? 0.0;

        return $fromPrice <= $orderAmount;
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @param string $carrier
     *
     * @return int
     * @throws Exception
     */
    public function getDefaultInsurance(string $carrier): int
    {
        $shippingAddress = $this->order->getShippingAddress();
        $shippingCountry = $shippingAddress ? $shippingAddress->getCountryId() : AbstractConsignment::CC_NL;

        if (AbstractConsignment::CC_NL === $shippingCountry) {
            return $this->getInsurance($carrier, self::INSURANCE_LOCAL_AMOUNT, $shippingCountry);
        }

        if (AbstractConsignment::CC_BE === $shippingCountry) {
            return $this->getInsurance($carrier, self::INSURANCE_BELGIUM_AMOUNT, $shippingCountry);
        }

        if (in_array($shippingCountry, AbstractConsignment::EURO_COUNTRIES)) {
            return $this->getInsurance($carrier, self::INSURANCE_EU_AMOUNT, $shippingCountry);
        }

        return $this->getInsurance($carrier, self::INSURANCE_ROW_AMOUNT, $shippingCountry);
    }

    /**
     * @throws Exception
     */
    private function getInsurance(string $carrierName, string $priceKey, string $shippingCountry): int
    {
        $total = $this->order->getGrandTotal();
        $settings = $this->helper->getStandardConfig($carrierName, 'default_options');
        $totalAfterPercentage = $total * (($settings[self::INSURANCE_PERCENTAGE] ?? 0) / 100);

        if (! isset($settings[$priceKey])
            || (int) $settings[$priceKey] === 0
            || $totalAfterPercentage < (int) $settings[self::INSURANCE_FROM_PRICE]) {
            return 0;
        }

        $carrier        = ConsignmentFactory::createByCarrierName($carrierName);
        $insuranceTiers = $carrier->getInsurancePossibilities($shippingCountry);
        sort($insuranceTiers);

        $insurance = 0;
        foreach ($insuranceTiers as $insuranceTier) {
            $totalPriceFallsIntoTier = $totalAfterPercentage <= $insuranceTier;
            $atMaxInsuranceTier      = $insuranceTier >= $settings[$priceKey];

            if ($totalPriceFallsIntoTier || $atMaxInsuranceTier) {
                $insurance = $insuranceTier;
                break;
            }
        }

        return $insurance;
    }

    /**
     * Get default of digital stamp weight
     *
     * @return string
     */
    public function getDigitalStampDefaultWeight(): string
    {
        return $this->helper->getCarrierConfig('digital_stamp/default_weight', 'myparcelnl_magento_postnl_settings/');
    }

    /**
     * Get package type ID as an int by default
     *
     * @return int
     */
    public function getPackageType(): int
    {
        if ($this->chosenOptions) {
            $keyIsPresent = array_key_exists('packageType', $this->chosenOptions);

            if ($keyIsPresent) {
                $packageType  = $this->chosenOptions['packageType'];

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
        if ($this->chosenOptions) {
            $keyIsPresent = array_key_exists('carrier', $this->chosenOptions);

            if ($keyIsPresent) {
                return $this->chosenOptions['carrier'];
            }
        }

        return self::getDefaultCarrier()->getName();
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
     * @throws Exception
     */
    public static function getDefaultCarrier(): AbstractCarrier
    {
        return CarrierFactory::createFromClass(CarrierDPD::class);
    }
}
