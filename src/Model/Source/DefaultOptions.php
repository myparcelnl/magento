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
use MyParcelNL\Magento\Model\Settings\InsuranceAmountSetting;
use MyParcelNL\Magento\Model\Shipment\OptionSource;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\ShipmentOptionsResolver;
use Throwable;

class DefaultOptions
{
    private const INSURANCE_FROM_PRICE     = 'insurance_from_price';
    private const INSURANCE_PERCENTAGE     = 'insurance_percentage';
    public const  DEFAULT_OPTION_VALUE     = 'default';

    private Config $config;
    private        $quote;
    private array  $chosenOptions;

    /** @var array<string,array<string,mixed>> default_options per carrier; the form asks per option */
    private array $settingsByCarrier = [];

    /** @var array<string,bool|null> what the quote's products force, by option; null is "no opinion" */
    private array $fromProducts = [];

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
     * The order's default for an option: the checkout's choice, then what the products force
     * (age check), then the carrier setting. The New Shipment page and the export both read this.
     */
    public function hasOptionSet(string $option, string $carrier): bool
    {
        return null !== $this->sourceOf($option, $carrier);
    }

    /**
     * Which OptionSource tier switched the option on, or null when it is off. The same answer as
     * hasOptionSet(), with its provenance kept.
     *
     * The order's stored value is tri-state: true is on, false is off, null inherits. A stored false
     * beats everything, an 18+ product included: only a merchant stores one.
     */
    public function sourceOf(string $option, string $carrier): ?int
    {
        $stored = $this->chosenOptions['shipmentOptions'][$option] ?? null;

        if (false === $stored) {
            return null;
        }

        if (ShipmentOption::AGE_CHECK === $option) {
            $fromProducts = $this->ageCheckFromProducts();

            // Asked before the checkout, unlike hasOptionSet() used to: both answer "on", but only
            // the product is the higher tier.
            if (true === $fromProducts) {
                return OptionSource::PRODUCT;
            }

            if (null === $stored && false === $fromProducts) {
                return null;
            }
        }

        if (true === $stored) {
            return in_array($option, $this->chosenOptions['merchantOptions'] ?? [], true)
                ? OptionSource::MERCHANT
                : OptionSource::CHECKOUT;
        }

        if (ShipmentOption::LARGE_FORMAT === $option) {
            return $this->hasDefaultLargeFormat($carrier, $option) ? OptionSource::CONFIGURATION : null;
        }

        return $this->hasDefaultOption($carrier, $option) ? OptionSource::CONFIGURATION : null;
    }

    /**
     * Memoised, false included: the answer is the quote's and cannot change while this instance
     * lives, and the New Shipment form asks once per carrier and package type.
     */
    private function ageCheckFromProducts(): ?bool
    {
        if (! array_key_exists('ageCheck', $this->fromProducts)) {
            $this->fromProducts['ageCheck'] =
                ShipmentOptionsResolver::getAgeCheckFromProduct($this->quote->getItems() ?? []);
        }

        return $this->fromProducts['ageCheck'];
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

        $settings  = $this->settingsFor($carrier);
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
        $settings = $this->settingsFor($carrier);

        if ('1' !== ($settings["{$option}_active"] ?? null)) {
            return false;
        }

        $fromPrice   = $settings["{$option}_from_price"] ?? 0;
        $orderAmount = $this->quote->getGrandTotal() ?? 0.0;

        return $fromPrice <= $orderAmount;
    }

    /**
     * What the merchant saved on this order, or else what the configuration asks for, in whole euros.
     *
     * The destination decides which of the four configured caps applies. It does **not** bound the
     * amount against the account's contract: that is one clamp, in ShipmentOptionsResolver, so the
     * posted admin override goes through it too.
     *
     * @throws \Exception
     */
    public function getDefaultInsurance(string $carrier): int
    {
        $stored = $this->chosenOptions['shipmentOptions'][ShipmentOption::INSURANCE] ?? null;

        if (null !== $stored) {
            return (int) $stored;
        }

        return $this->getInsurance($carrier, $this->insuranceCapKey(), true);
    }

    /**
     * The amount to insure for when another option requires insurance: getDefaultInsurance()
     * without the from-price, which would otherwise leave the companion at 0.
     */
    public function getRequiredInsurance(string $carrier): int
    {
        return $this->getInsurance($carrier, $this->insuranceCapKey(), false);
    }

    private function insuranceCapKey(): string
    {
        $shippingAddress = $this->quote->getShippingAddress();

        return InsuranceAmountSetting::fieldFor(
            $shippingAddress ? $shippingAddress->getCountryId() : null,
            ObjectManager::getInstance()->get(StoredAccount::class)->propositionForStore((int) $this->quote->getStoreId())
        );
    }

    /**
     * The insured value the order earns, never above the configured cap. Rounded up: under-insuring
     * a parcel is the worse of the two errors.
     *
     * A cap of 0 means insurance is off. It is indistinguishable from a contract minimum of 0, which
     * is a pre-existing ambiguity kept on purpose — reading 0 as "insure at the minimum" would switch
     * insurance on for every merchant who never configured it.
     */
    private function getInsurance(string $carrierName, string $priceKey, bool $applyFromPrice): int
    {
        $total                = $this->quote->getGrandTotal();
        $settings             = $this->settingsFor($carrierName);
        $totalAfterPercentage = $total * ((int) ($settings[self::INSURANCE_PERCENTAGE] ?? 0) / 100);

        if (! isset($settings[$priceKey])
            || (int) $settings[$priceKey] === 0
            || ($applyFromPrice && $totalAfterPercentage < (int) $settings[self::INSURANCE_FROM_PRICE])) {
            return 0;
        }

        return (int) min(ceil($totalAfterPercentage), (int) $settings[$priceKey]);
    }

    /** The digital stamp weight in grams a merchant saved on the order, or the configured one. */
    public function getDigitalStampDefaultWeight(): int
    {
        $saved = $this->getSavedDigitalStampWeight();

        if (null !== $saved) {
            return $saved;
        }

        return (int) $this->config->getConfigValue('myparcelnl_magento_postnl_settings/digital_stamp/default_weight', (int) $this->quote->getStoreId());
    }

    /** The digital stamp weight in grams a merchant saved on the order; null when none was saved. */
    public function getSavedDigitalStampWeight(): ?int
    {
        $saved = $this->chosenOptions['digitalStampWeight'] ?? null;

        return null === $saved ? null : (int) $saved;
    }

    /** The number of labels a merchant saved on the order, or one. */
    public function getLabelAmount(): int
    {
        return max(1, (int) ($this->chosenOptions['labelAmount'] ?? 1));
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

    /**
     * One carrier's default_options subtree. The admin form asks per package type and per option, so
     * this runs well over a hundred times per render.
     *
     * Empty, never null, for a carrier with no settings: a null would make isset() below miss the
     * memo on every call for exactly that carrier.
     *
     * @return array<string,mixed>
     */
    private function settingsFor(string $carrier): array
    {
        if (isset($this->settingsByCarrier[$carrier])) {
            return $this->settingsByCarrier[$carrier];
        }

        return $this->settingsByCarrier[$carrier] = (array) $this->config->getCarrierConfig(
            $carrier,
            'default_options',
            (int) $this->quote->getStoreId()
        );
    }
}
