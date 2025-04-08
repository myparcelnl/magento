<?php

declare(strict_types=1);

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
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

class DefaultOptions
{
    private const INSURANCE_FROM_PRICE     = 'insurance_from_price';
    private const INSURANCE_LOCAL_AMOUNT   = 'insurance_local_amount';
    private const INSURANCE_BELGIUM_AMOUNT = 'insurance_belgium_amount';
    private const INSURANCE_EU_AMOUNT      = 'insurance_eu_amount';
    private const INSURANCE_ROW_AMOUNT     = 'insurance_row_amount';
    private const INSURANCE_PERCENTAGE     = 'insurance_percentage';
    public const  DEFAULT_OPTION_VALUE     = 'default';

    private Config $config;
    private        $quote;
    private array  $chosenOptions;

    /**
     * In Magento both Order and Quote have getData() and getShippingAddress() methods.
     * However, they do not share an interface (?!), so we cannot type hint for both.
     * As long as this class only needs to getData and getShippingAddress, we can use either.
     *
     * @param Order|Quote $quote
     */
    public function __construct($quote)
    {
        $objectManager = ObjectManager::getInstance();
        $this->config  = $objectManager->get(Config::class);
        $this->quote   = $quote;
        try {
            $this->chosenOptions = DeliveryOptionsAdapterFactory::create(
                (array) json_decode($quote->getData(Config::FIELD_DELIVERY_OPTIONS), true, 4, JSON_THROW_ON_ERROR)
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
        if (array_key_exists('shipmentOptions', $this->chosenOptions) &&
            array_key_exists($option, $this->chosenOptions['shipmentOptions']) &&
            $this->chosenOptions['shipmentOptions'][$option]
        ) {
            return true;
        }

        return $this->hasDefaultOption($carrier, $option);
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
        $price = $this->quote->getGrandTotal();

        $settings  = $this->config->getCarrierConfig($carrier, 'default_options');
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
        $settings = $this->config->getCarrierConfig($carrier, 'default_options');

        if ('1' !== ($settings["{$option}_active"] ?? null)) {
            return false;
        }

        $fromPrice   = $settings["{$option}_from_price"] ?? 0;
        $orderAmount = $this->quote->getGrandTotal() ?? 0.0;

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
        $shippingAddress = $this->quote->getShippingAddress();
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
        $total                = $this->quote->getGrandTotal();
        $settings             = $this->config->getCarrierConfig($carrierName, 'default_options');
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
     * @return int
     */
    public function getDigitalStampDefaultWeight(): int
    {
        return (int) $this->config->getConfigValue('myparcelnl_magento_postnl_settings/digital_stamp/default_weight');
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
    public function getCarrierName(): string
    {
        if ($this->chosenOptions) {
            $keyIsPresent = array_key_exists('carrier', $this->chosenOptions);

            if ($keyIsPresent) {
                return $this->chosenOptions['carrier'];
            }
        }

        return $this->config->getDefaultCarrierName($this->quote->getShippingAddress());
    }
}
