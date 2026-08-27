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

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Adapter\DeliveryOptions\DeliveryOptionsFactory;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\Config;
use Throwable;

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
            $this->chosenOptions = DeliveryOptionsFactory::create(
                (array) json_decode($quote->getData(Config::FIELD_DELIVERY_OPTIONS), true, 4, JSON_THROW_ON_ERROR)
            )->toArray();
        } catch (Throwable $e) {
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
        if (ShipmentOption::LARGE_FORMAT === $option) {
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

        $settings  = $this->config->getCarrierConfig($carrier, 'default_options', (int) $this->quote->getStoreId());
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
        $settings = $this->config->getCarrierConfig($carrier, 'default_options', (int) $this->quote->getStoreId());

        if ('1' !== ($settings["{$option}_active"] ?? null)) {
            return false;
        }

        $fromPrice   = $settings["{$option}_from_price"] ?? 0;
        $orderAmount = $this->quote->getGrandTotal() ?? 0.0;

        return $fromPrice <= $orderAmount;
    }

    /**
     * What the merchant's configuration asks for on this order, in whole euros.
     *
     * The destination decides which of the four configured caps applies. It does **not** bound the
     * amount against the account's contract: that is one clamp, in ShipmentOptionsResolver, so the
     * posted admin override goes through it too (DR-19).
     *
     * @throws \Exception
     */
    public function getDefaultInsurance(string $carrier): int
    {
        $shippingAddress = $this->quote->getShippingAddress();
        $shippingCountry = $shippingAddress ? $shippingAddress->getCountryId() : CountryCode::CC_NL;

        if (CountryCode::CC_NL === $shippingCountry) {
            return $this->getInsurance($carrier, self::INSURANCE_LOCAL_AMOUNT);
        }

        if (CountryCode::CC_BE === $shippingCountry) {
            return $this->getInsurance($carrier, self::INSURANCE_BELGIUM_AMOUNT);
        }

        if (CountryCode::isEu($shippingCountry)) {
            return $this->getInsurance($carrier, self::INSURANCE_EU_AMOUNT);
        }

        return $this->getInsurance($carrier, self::INSURANCE_ROW_AMOUNT);
    }

    /**
     * The insured value the order earns, never above the configured cap. Rounded up: under-insuring
     * a parcel is the worse of the two errors.
     *
     * A cap of 0 means insurance is off. It is indistinguishable from a contract minimum of 0, which
     * is a pre-existing ambiguity kept on purpose — reading 0 as "insure at the minimum" would switch
     * insurance on for every merchant who never configured it.
     */
    private function getInsurance(string $carrierName, string $priceKey): int
    {
        $total                = $this->quote->getGrandTotal();
        $settings             = $this->config->getCarrierConfig($carrierName, 'default_options', (int) $this->quote->getStoreId());
        $totalAfterPercentage = $total * ((int) ($settings[self::INSURANCE_PERCENTAGE] ?? 0) / 100);

        if (! isset($settings[$priceKey])
            || (int) $settings[$priceKey] === 0
            || $totalAfterPercentage < (int) $settings[self::INSURANCE_FROM_PRICE]) {
            return 0;
        }

        return (int) min(ceil($totalAfterPercentage), (int) $settings[$priceKey]);
    }

    /**
     * Get default of digital stamp weight
     *
     * @return int
     */
    public function getDigitalStampDefaultWeight(): int
    {
        return (int) $this->config->getConfigValue('myparcelnl_magento_postnl_settings/digital_stamp/default_weight', (int) $this->quote->getStoreId());
    }

    /**
     * The stored name, unresolved. Use this when showing or passing the value on; getPackageType()
     * has to answer with an int and therefore has to substitute.
     *
     * Customer-influenced, so escape it at the output site.
     */
    public function getPackageTypeName(): ?string
    {
        $name = $this->chosenOptions['packageType'] ?? null;

        return null === $name ? null : (string) $name;
    }

    /**
     * Substitutes the default for a name we do not recognise, and logs when it does.
     *
     * @return int
     */
    public function getPackageType(): int
    {
        $name = $this->chosenOptions['packageType'] ?? null;
        $id   = PackageType::toIdOrNull($name);

        if (null === $id && null !== $name) {
            Logger::warning(sprintf(
                'Unknown package type "%s" in the stored delivery options; falling back to "%s".',
                (string) $name,
                PackageType::DEFAULT_NAME
            ));
        }

        return $id ?? PackageType::PACKAGE;
    }

    /**
     * @return string
     */
    public function getCarrierName(): string
    {
        return $this->chosenOptions['carrier'] ?? $this->config->getDefaultCarrierName($this->quote->getShippingAddress(), (int) $this->quote->getStoreId());
    }
}
