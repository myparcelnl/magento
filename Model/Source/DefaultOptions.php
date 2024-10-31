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
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Magento\Service\Weight\WeightService;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Support\Str;

class DefaultOptions
{
    // Maximum characters length of company name.
    private const COMPANY_NAME_MAX_LENGTH = 50;
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

    private ConfigService $configService;
    private Order         $order;
    private array         $chosenOptions;
    private WeightService $weightService;

    /**
     * @param Order $order
     */
    public function __construct(Order $order)
    {
        $objectManager       = ObjectManager::getInstance();
        $this->configService = $objectManager->get(ConfigService::class);
        $this->weightService = $objectManager->get(WeightService::class);
        $this->order         = $order;
        try {
            $this->chosenOptions = DeliveryOptionsAdapterFactory::create(
                (array) json_decode($order->getData(ConfigService::FIELD_DELIVERY_OPTIONS), true)
            )->toArray();
        } catch (Exception $e) {
            $this->chosenOptions = [];
        }
    }

    /**
     * Get default of the option
     *
     * @param string $option 'only_recipient'|'signature'|'receipt_code'|'return'|'large_format'
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
        if (array_key_exists('shipmentOptions', $this->chosenOptions) &&
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
        return Str::limit($company, self::COMPANY_NAME_MAX_LENGTH);
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
        $weight = $this->weightService->convertToGrams($this->order->getWeight());

        $settings  = $this->configService->getCarrierConfig($carrier, 'default_options');
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
     * @param string $carrier
     * @param string $option
     *
     * @return bool
     */
    public function hasDefaultOption(string $carrier, string $option): bool
    {
        $settings = $this->configService->getCarrierConfig($carrier, 'default_options');

        if ('1' !== ($settings["{$option}_active"] ?? null)) {
            return false;
        }

        $fromPrice   = $settings["{$option}_from_price"] ?? 0;
        $orderAmount = $this->order->getGrandTotal() ?? 0.0;

        return $fromPrice <= $orderAmount;
    }

    /**
     * Get default value of insurance based on order grand total
     *
     * @param string $carrier
     *
     * @return int
     * @throws \Exception
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
        $total                = $this->order->getGrandTotal();
        $settings             = $this->configService->getCarrierConfig($carrierName, 'default_options');
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
        return $this->configService->getConfigValue('myparcelnl_magento_postnl_settings/digital_stamp/default_weight');
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
                $packageType = $this->chosenOptions['packageType'];

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

        return CarrierPostNL::NAME;
    }

    /**
     * TODO: In the future, when multiple carriers will be available for Rest of World shipments, replace PostNL with a setting for default carrier
     *
     * @return \MyParcelNL\Sdk\src\Model\Carrier\AbstractCarrier
     * @throws \Exception
     */
    public static function getDefaultCarrier(): AbstractCarrier
    {
        return CarrierFactory::createFromClass(CarrierPostNL::class);
    }
}
