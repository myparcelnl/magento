<?php
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

namespace MyParcelNL\Magento\Model\Rate;

use Countable;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class Result extends \Magento\Shipping\Model\Rate\Result
{
    private const FIRST_PART  = 0;
    private const SECOND_PART = 1;
    private const THIRD_PART  = 2;
    private const FOURTH_PART = 3;

    /**
     * @var Checkout
     */
    private $myParcelHelper;

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
        $this->parentMethods  = explode(',', $this->myParcelHelper->getGeneralConfig('shipping_methods/methods'));
        $package->setCurrentCountry(
            $this->getQuoteFromCardOrSession()
                ->getShippingAddress()
                ->getCountryId()
        );
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
            'same_day_delivery_only_recipient'  => 'delivery/same_day_delivery/only_recipient',
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
        $selectedCountry = $this->session->getQuote()
            ->getShippingAddress()
            ->getCountryId();

        if (AbstractConsignment::CC_NL !== $selectedCountry && AbstractConsignment::CC_BE !== $selectedCountry) {
            return;
        }

        $parentShippingMethod = $parentRate->getData('carrier');
        if (! in_array($parentShippingMethod, $this->parentMethods, true)) {
            return;
        }

        foreach (self::getMethods() as $settingPath) {
            foreach (Data::CARRIERS as $carrier) {
                if ($this->hasMyParcelRate($settingPath)) {
                    return;
                }

                $fullPath = $this->getFullSettingPath(Data::CARRIERS_XML_PATH_MAP[$carrier], $settingPath);
                if ($fullPath) {
                    $method = $this->getShippingMethod(
                        $fullPath,
                        $parentRate
                    );

                    $this->_rates[] = $method;
                }
            }
        }
    }

    /**
     * @param  string $settingPath
     *
     * @return bool
     */
    private function hasMyParcelRate(string $settingPath): bool
    {
        foreach ($this->_rates as $rate) {
            if ($rate->getData('method_title') === $this->createTitle($settingPath)) {
                return true;
            }
        }

        return false;
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
        $settingPathParts = explode("/", $settingPath);
        $activeDelivery   = (bool) $this->myParcelHelper->getConfigValue($map . 'delivery/active');
        // Check if delivery is active.
        if ('delivery' === $settingPathParts[0]) {
            return $activeDelivery;
        }
        // Check if delivery, morning or evening delivery are active.
        if ($activeDelivery && ('morning' === $settingPathParts[0] || 'evening' === $settingPathParts[0])) {
            return (bool) $this->myParcelHelper->getConfigValue($map . $settingPathParts[0] . '/active');
        }
        // Check if the setting has an additional option like signature or only_recipient and see if it is active.
        if (count($settingPathParts) === 2) {
            return $this->hasSettingAdditionalOption($settingPathParts, $map, $separator);
        }
        // Check if there are multiple additional options like signature and only_recipient and check if they are both active.
        if (count($settingPathParts) === 3) {
            return $this->hasSettingAdditionalOptions($settingPathParts, $map, $separator);
        }

        return (bool) $this->myParcelHelper->getConfigValue($map . $settingPath . $separator . 'active');
    }

    /**
     * @param  array  $basePath
     * @param  string $map
     * @param  string $separator
     *
     * @return bool
     */
    private function hasSettingAdditionalOption(array $basePath, string $map, string $separator): bool
    {
        [$base, $setting] = $basePath;
        $settingActive = $map . $base . '/' . $setting . $separator . 'active';

        return (bool) $this->myParcelHelper->getConfigValue($settingActive);
    }

    /**
     * @param  array  $basePath
     * @param  string $map
     * @param  string $separator
     * @param  bool   $settingActive
     *
     * @return bool
     */
    private function hasSettingAdditionalOptions(
        array  $basePath,
        string $map,
        string $separator,
        bool   $settingActive = false
    ): bool {
        $base = array_shift($basePath);

        foreach ($basePath as $setting) {
            $settingActive = (bool) $this->myParcelHelper->getConfigValue(
                $map . $base . '/' . $setting . $separator . 'active'
            );

            if (! $settingActive) {
                break;
            }
        }

        return $settingActive;
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
        $settingPath    = explode('/', $settingPath);

        // Check if the selected delivery options are delivery, only_recipient and signature
        // delivery/only_recipient/signature
        if (isset($settingPath[self::THIRD_PART], $settingPath[self::FOURTH_PART]) && 'delivery' === $settingPath[1]) {
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

        // For mailbox and digital stamp the base price should not be calculated
        if (AbstractConsignment::PACKAGE_TYPE_MAILBOX_NAME === $settingPath[self::FIRST_PART] || AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP_NAME === $settingPath[self::SECOND_PART]) {
            $basePrice = 0;
        }

        $settingPath = implode("/", $settingPath);
        $settingFee  += (float) $this->myParcelHelper->getConfigValue($settingPath . 'fee');

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
    private function getQuoteFromCardOrSession()
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
