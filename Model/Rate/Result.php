<?php

declare(strict_types=1);
/**
 * Get all rates depending on base price
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @copyright   2010-2019 MyParcel
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Rate;

use Countable;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class Result extends \Magento\Shipping\Model\Rate\Result
{
    private const FIRST_PART                  = 0;
    private const SECOND_PART                 = 1;
    private const THIRD_PART                  = 2;
    private const FOURTH_PART                 = 3;
    private const  CARRIERS_WITH_MAILBOX       = [
        CarrierPostNL::NAME,
        CarrierDHLForYou::NAME,
        CarrierDPD::NAME,
    ];
    public const  CARRIERS_WITH_DIGITAL_STAMP = [
        CarrierPostNL::NAME,
    ];

    /**
     * @var Checkout
     */
    private $myParcelHelper;

    /**
     * @var \MyParcelBE\Magento\Model\Sales\Repository\PackageRepository
     */
    private $package;

    /**
     * @var array
     */
    private $parentMethods;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var \Magento\Backend\Model\Session\Quote
     */
    private $quote;

    /**
     * Result constructor.
     *
     * @param  \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param  \Magento\Backend\Model\Session\Quote       $quote
     * @param  Session                                    $session
     * @param  Checkout                                   $myParcelHelper
     * @param  PackageRepository                          $package
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @internal param \Magento\Checkout\Model\Session $session
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Backend\Model\Session\Quote       $quote,
        Session                                    $session,
        Checkout                                   $myParcelHelper,
        PackageRepository                          $package
    ) {
        parent::__construct($storeManager);

        $this->myParcelHelper = $myParcelHelper;
        $this->session        = $session;
        $this->quote          = $quote;
        $this->parentMethods  = explode(',', $this->myParcelHelper->getGeneralConfig('shipping_methods/methods') ?? '');
        $package->setCurrentCountry(
            $this->getQuoteFromCartOrSession()
                ->getShippingAddress()
                ->getCountryId()
        );
        $this->package = $package;
    }

    /**
     * Add a rate to the result
     *
     * @param  \Magento\Quote\Model\Quote\Address\RateResult\AbstractResult|\Magento\Shipping\Model\Rate\Result $result
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function append($result)
    {
        if ($result instanceof \Magento\Quote\Model\Quote\Address\RateResult\Error) {
            $this->setError(true);
        }
        if ($result instanceof \Magento\Quote\Model\Quote\Address\RateResult\Method) {
            $this->_rates[] = $result;
            $this->addMyParcelRates($result);
        } elseif ($result instanceof \Magento\Quote\Model\Quote\Address\RateResult\AbstractResult) {
            $this->_rates[] = $result;
        } elseif ($result instanceof \Magento\Shipping\Model\Rate\Result) {
            $rates = $result->getAllRates();
            foreach ($rates as $rate) {
                $this->append($rate);
                $this->addMyParcelRates($rate);
            }
        }

        return $this;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public static function getMethods(): array
    {
        return [
            'pickup'                            => 'pickup',
            'standard'                          => 'delivery',
            'standard_signature'                => 'delivery/signature',
            'standard_only_recipient'           => 'delivery/only_recipient',
            'standard_only_recipient_signature' => 'delivery/only_recipient/signature',
            'morning'                           => 'morning',
            'morning_only_recipient'            => 'morning/only_recipient',
            'morning_only_recipient_signature'  => 'morning/only_recipient/signature',
            'evening'                           => 'evening',
            'evening_only_recipient'            => 'evening/only_recipient',
            'evening_only_recipient_signature'  => 'evening/only_recipient/signature',
            'mailbox'                           => 'mailbox',
            'digital_stamp'                     => 'digital_stamp',
            'same_day_delivery'                 => 'delivery/same_day_delivery',
            'same_day_delivery_only_recipient'  => 'delivery/only_recipient/same_day_delivery',
        ];
    }

    /**
     * Add MyParcel shipping rates
     *
     * @param  \Magento\Quote\Model\Quote\Address\RateResult\Method $parentRate
     *
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function addMyParcelRates(Method $parentRate): void
    {
        $parentShippingMethod = $parentRate->getData('carrier');
        if (! in_array($parentShippingMethod, $this->parentMethods, true)) {
            return;
        }

        foreach (Data::CARRIERS_XML_PATH_MAP as $carrier => $carrierPath) {
            if (! $this->myParcelHelper->getConfigValue("{$carrierPath}delivery/active")) {
                continue;
            }

            if (in_array($carrier, self::CARRIERS_WITH_MAILBOX)) {
                $this->package->setMailboxSettings($carrierPath);
            }

            if (in_array($carrier, self::CARRIERS_WITH_DIGITAL_STAMP)) {
                $this->package->setDigitalStampSettings($carrierPath);
            }

            $packageType = $this->package->selectPackageType(
                $this->getQuoteFromCartOrSession()->getAllItems(),
                $carrierPath
            );

            foreach (self::getMethods() as $settingPath) {
                $fullPath  = $this->getFullSettingPath($carrierPath, $settingPath);
                $separator = $this->isBaseSetting($settingPath) ? '/' : '_';
                $pathParts = explode('/', $settingPath ?? '');

                if (! $fullPath
                    || ! $this->isRelevantOption($packageType, $pathParts[0])
                    || ! $this->isSettingActive($carrierPath, $settingPath, $separator)
                ) {
                    continue;
                }

                $this->_rates[] = $this->getShippingMethod(
                    $fullPath,
                    $parentRate
                );
            }
        }
    }

    /**
     * @param  string $packageType
     * @param  string $fromOptionPath
     *
     * @return bool
     */
    private function isRelevantOption(string $packageType, string $fromOptionPath): bool
    {
        switch ($packageType) {

            case AbstractConsignment::PACKAGE_TYPE_LETTER_NAME:
                return false;

            case AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME:
                return AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME === $fromOptionPath;

            case AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME:
                if (AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME === $fromOptionPath) {
                    return $this->package->isPickupMailboxActive();
                }
                return AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME === $fromOptionPath;

            default:
                return ! in_array($fromOptionPath,
                    [
                        AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME,
                        AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME,
                    ]
                );
        }
    }

    /**
     * Check if a given map/setting combination is active. If the setting is not a top level setting its parent group
     * will be checked for an "active" setting. If this is disabled this will return false;
     *
     * @param  string $map
     * @param  string $settingPath
     * @param  string $separator
     *
     * @return bool
     */
    private function isSettingActive(string $map, string $settingPath, string $separator): bool
    {
        $settingPathParts = explode("/", $settingPath ?? '');
        $baseSetting      = $settingPathParts[0];

        if (! $this->myParcelHelper->getConfigValue("{$map}{$baseSetting}/active")) {
            return false;
        }

        foreach ($settingPathParts as $index => $option) {
            if (0 === $index) {
                continue;
            }
            $fullPath = "{$map}delivery/{$option}{$separator}active";
            if (! $this->myParcelHelper->getConfigValue($fullPath)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  string $map
     * @param  string $settingPath
     *
     * @return string|null
     */
    private function getFullSettingPath(string $map, string $settingPath): ?string
    {
        $separator     = $this->isBaseSetting($settingPath) ? '/' : '_';
        $settingActive = $this->isSettingActive($map, $settingPath, $separator);

        if ($settingActive) {
            return $map . $settingPath . $separator;
        }

        return null;
    }

    /**
     * @param  string $settingPath
     *
     * @return bool
     */
    private function isBaseSetting(string $settingPath): bool
    {
        return strpos($settingPath, '/') === false;
    }

    /**
     * @param  string|null $settingPath
     * @param  Method|null $parentRate
     *
     * @return Method
     */
    private function getShippingMethod(string $settingPath, Method $parentRate): Method
    {
        $method = clone $parentRate;
        $this->myParcelHelper->setBasePrice($parentRate->getData('price'));

        $title = $this->createTitle($settingPath);
        $price = $this->getPrice($settingPath);

        $method->setData('cost', 0);
        // Trim the separator off the end of the settings path
        $method->setData('method', substr($settingPath, 0, -1));
        $method->setData('method_title', $title);
        $method->setPrice($price);

        return $method;
    }

    /**
     * Create title for method
     * If no title isset in config, get title from translation
     *
     * @param $settingPath
     *
     * @return \Magento\Framework\Phrase|mixed
     */
    private function createTitle($settingPath)
    {
        return __(substr($settingPath, 0, -1));
    }

    /**
     * Create price
     * Calculate price if multiple options are chosen
     *
     * @param $settingPath
     *
     * @return float
     * @todo: Several improvements are possible within this method
     */
    private function getPrice($settingPath): float
    {
        $basePrice  = $this->myParcelHelper->getBasePrice();
        $settingFee = 0;

        // Explode settingPath like: myparcelnl_magento_postnl_settings/delivery/only_recipient/signature
        $settingPath    = explode('/', $settingPath ?? '');

        // Check if the selected delivery options are delivery, only_recipient and signature
        // delivery/only_recipient/signature
        if (isset($settingPath[self::THIRD_PART], $settingPath[self::FOURTH_PART]) && 'delivery' === $settingPath[self::SECOND_PART]) {
            $settingFee += (float) $this->myParcelHelper->getConfigValue(
                sprintf(
                    "%s/%s/%s_fee",
                    $settingPath[self::FIRST_PART],
                    $settingPath[self::SECOND_PART],
                    $settingPath[self::THIRD_PART]
                )
            );
            $settingFee += (float) $this->myParcelHelper->getConfigValue(
                sprintf(
                    "%s/%s/%sfee",
                    $settingPath[self::FIRST_PART],
                    $settingPath[self::SECOND_PART],
                    $settingPath[self::FOURTH_PART]
                )
            );
        }

        // Check if the selected delivery is morning or evening and select the fee
        if (AbstractConsignment::DELIVERY_TYPE_MORNING_NAME === $settingPath[self::SECOND_PART] || AbstractConsignment::DELIVERY_TYPE_EVENING_NAME === $settingPath[self::SECOND_PART]) {
            $settingFee = (float) $this->myParcelHelper->getConfigValue(
                sprintf("%s/%s/fee", $settingPath[self::FIRST_PART], $settingPath[self::SECOND_PART])
            );

            // change delivery type if there is a signature selected
            if (isset($settingPath[self::FOURTH_PART])) {
                $settingPath[self::SECOND_PART] = 'delivery';
            }
            // Unset only_recipient to select the correct price
            unset($settingPath[self::THIRD_PART]);
        }

        $settingFee  += (float) $this->myParcelHelper->getConfigValue(implode('/', $settingPath ?? []) . 'fee');

        // For mailbox and digital stamp the base price should not be calculated
        if (AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME === $settingPath[self::SECOND_PART]) {
            // for international mailbox, we have a different price :-)
            $cc = $this->session->getQuote()->getShippingAddress()->getCountryId();
            if ($cc !== 'NL') {
                $settingFee = (float) $this->myParcelHelper->getConfigValue(
                    sprintf("%s/%s/international_fee", $settingPath[self::FIRST_PART], $settingPath[self::SECOND_PART])
                );
            }
            return min($settingFee, $basePrice);
        }
        if (AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME === $settingPath[self::SECOND_PART]){
            return min($settingFee, $basePrice);
        }

        return $basePrice + $settingFee;
    }

    /**
     * Can't get quote from session\Magento\Checkout\Model\Session::getQuote()
     * To fix a conflict with buckeroo, use \Magento\Checkout\Model\Cart::getQuote() like the following
     *
     * @return \Magento\Quote\Model\Quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getQuoteFromCartOrSession()
    {
        if ($this->quote->getQuoteId() != null && $this->quote->getQuote()
            && $this->quote->getQuote() instanceof Countable
            && count($this->quote->getQuote())
        ) {
            return $this->quote->getQuote();
        }

        return $this->session->getQuote();
    }
}
