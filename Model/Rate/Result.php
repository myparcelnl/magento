<?php
/**
 * Get all rates depending on base price
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Rate;

use Countable;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;

class Result extends \Magento\Shipping\Model\Rate\Result
{
    /**
     * @var \Magento\Eav\Model\Entity\Collection\AbstractCollection[]
     */
    private $products;

    /**
     * @var Checkout
     */
    private $myParcelHelper;

    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * @var array
     */
    private $parentMethods = [];

    /**
     * @var bool
     */
    private static $myParcelRatesAlreadyAdded = false;
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
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Backend\Model\Session\Quote       $quote
     * @param Session                                    $session
     * @param Checkout                                   $myParcelHelper
     * @param PackageRepository                          $package
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @internal param \Magento\Checkout\Model\Session $session
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Backend\Model\Session\Quote $quote,
        Session $session,
        Checkout $myParcelHelper,
        PackageRepository $package
    ) {
        parent::__construct($storeManager);

        $this->myParcelHelper = $myParcelHelper;
        $this->package        = $package;
        $this->session        = $session;
        $this->quote          = $quote;
        $this->parentMethods  = explode(',', $this->myParcelHelper->getGeneralConfig('shipping_methods/methods', true));
        $this->package->setCurrentCountry($this->getQuoteFromCardOrSession()->getShippingAddress()->getCountryId());
        $this->products = $this->getQuoteFromCardOrSession()->getItems();
    }

    /**
     * Add a rate to the result
     *
     * @param \Magento\Quote\Model\Quote\Address\RateResult\AbstractResult|\Magento\Shipping\Model\Rate\Result $result
     *
     * @return $this
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
            'morning_only_recipient'            => 'morning/only_recipient',
            'morning_only_recipient_signature'  => 'morning/only_recipient/signature',
            'evening_only_recipient'            => 'evening/only_recipient',
            'evening_only_recipient_signature'  => 'evening/only_recipient/signature',
            'mailbox'                           => 'mailbox',
            'digital_stamp'                     => 'digital_stamp'
        ];
    }

    /**
     * Add MyParcel shipping rates
     *
     * @param $parentRate \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function addMyParcelRates($parentRate)
    {
        $selectedCountry = $this->session->getQuote()->getShippingAddress()->getCountryId();

        if ($selectedCountry != 'NL' && $selectedCountry != 'BE') {
            return;
        }

        if ($this::$myParcelRatesAlreadyAdded) {
            return;
        }

        $parentShippingMethod = $parentRate->getData('carrier');
        if (! in_array($parentShippingMethod, $this->parentMethods)) {
            return;
        }

        foreach ($this->getMethods() as $alias => $settingPath) {
            $map = Data::CARRIERS_XML_PATH_MAP['postnl'];

            $method = $this->getShippingMethod(
                $this->getFullSettingPath($map, $settingPath),
                $parentRate
            );

            $this->_rates[] = $method;
        }

        $this::$myParcelRatesAlreadyAdded = true;
    }

    /**
     * Check if a given map/setting combination is active. If the setting is not a top level setting its parent group
     * will be checked for an "active" setting. If this is disabled this will return false;
     *
     * @param        $map
     * @param string $settingPath
     *
     * @return bool
     */
    private function isSettingActive(string $map, string $settingPath): bool
    {
        $settingName       = $this->getFullSettingPath($map, $settingPath);
        $settingActive     = $this->myParcelHelper->getConfigValue($settingName . 'active');
        $baseSettingActive = '1';

        if (! $this->isBaseSetting($settingPath)) {
            $baseSetting = $map . explode("/", $settingPath)[0];

            $baseSettingActive = $this->myParcelHelper->getConfigValue($baseSetting . '/active');
        }

        return $settingActive === '1' && $baseSettingActive === '1';
    }

    /**
     * @param string $map
     * @param string $settingPath
     *
     * @return string
     */
    private function getFullSettingPath(string $map, string $settingPath): string
    {
        $separator = $this->isBaseSetting($settingPath) ? '/' : '_';

        return $map . $settingPath . $separator;
    }

    /**
     * @param string $settingPath
     *
     * @return bool
     */
    private function isBaseSetting(string $settingPath): bool
    {
        return strpos($settingPath, '/') === false;
    }

    /**
     * @param string $settingPath
     * @param Method $parentRate
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
        return __(substr($settingPath, 0, strlen($settingPath) - 1));
    }

    /**
     * Create price
     * Calculate price if multiple options are chosen
     *
     * @param $settingPath
     *
     * @return float
     * @todo: Several improvements are possible within this method
     *
     */
    private function getPrice($settingPath): float
    {
        $basePrice  = $this->myParcelHelper->getBasePrice();
        $settingFee = 0;

        // Explode settingPath like: myparcelnl_magento_postnl_settings/delivery/only_recipient/signature
        $settingPath = explode("/", $settingPath);

        // Check if the selected delivery options are delivery, only_recipient and signature
        // delivery/only_recipient/signature
        if ($settingPath[1] == 'delivery' && isset($settingPath[2]) && isset($settingPath[3])) {
            $settingFee += (float) $this->myParcelHelper->getConfigValue($settingPath[0] . '/' . $settingPath[1] . '/' . $settingPath[2] . '_' . 'fee');
            $settingFee += (float) $this->myParcelHelper->getConfigValue($settingPath[0] . '/' . $settingPath[1] . '/' . $settingPath[3] . 'fee');
        }

        // Check if the selected delivery is morning or evening and select the fee
        if ($settingPath[1] == 'morning' || $settingPath[1] == 'evening') {
            $settingFee = (float) $this->myParcelHelper->getConfigValue($settingPath[0] . '/' . $settingPath[1] . '/' . 'fee');

            // change delivery type if there is a signature selected
            if (isset($settingPath[3])) {
                $settingPath[1] = 'delivery';
            }
            // Unset only_recipient to select the correct price
            unset($settingPath[2]);
        }

        // For mailbox and digital stamp the base price should not be calculated
        if ($settingPath[1] == 'mailbox' || $settingPath[1] == 'digital_stamp') {
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
        if ($this->quote->getQuoteId() != null &&
            $this->quote->getQuote() &&
            $this->quote->getQuote() instanceof Countable &&
            count($this->quote->getQuote())
        ) {
            return $this->quote->getQuote();
        }

        return $this->session->getQuote();
    }
}
