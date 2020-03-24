<?php
/**
 * Get all rates depending on base price
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe/magento
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Rate;

use Countable;
use Magento\Checkout\Model\Session;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use MyParcelBE\Magento\Helper\Checkout;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Sales\Repository\PackageRepository;

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
    private $myParcelRatesAlreadyAdded = false;
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

        $this->parentMethods = explode(',', $this->myParcelHelper->getGeneralConfig('shipping_methods/methods', true));
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
        if ($result instanceof \Magento\Quote\Model\Quote\Address\RateResult\AbstractResult) {
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
    private function getMethods(): array
    {
        return [
            'pickup'             => 'pickup',
            'standard'           => 'delivery',
            'standard_signature' => 'delivery/signature',
        ];
    }

    /**
     * Add MyParcel shipping rates.
     *
     * @param Method $parentRate
     */
    private function addMyParcelRates($parentRate): void
    {
        if ($this->myParcelRatesAlreadyAdded) {
            return;
        }

        $currentCarrier = $parentRate->getData('carrier');
        if (! in_array($currentCarrier, $this->parentMethods)) {
            return;
        }

        if (empty($this->products)) {
            $this->package->setWeightFromQuoteProducts($this->products);
        }

        foreach ($this->getMethods() as $alias => $settingPath) {
            foreach (Data::CARRIERS as $carrier) {
                $map = Data::CARRIERS_XML_PATH_MAP[$carrier];

                if (! $this->isSettingActive($map, $settingPath)) {
                    continue;
                }

                $method = $this->getShippingMethod(
                    $this->getFullSettingPath($map, $settingPath),
                    $parentRate
                );

                $this->append($method);
            }
        }

        $this->myParcelRatesAlreadyAdded = true;
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
        $settingName   = $this->getFullSettingPath($map, $settingPath);
        $settingActive = $this->myParcelHelper->getConfigValue($settingName . 'active');
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
        $title = $this->myParcelHelper->getConfigValue(Data::XML_PATH_BPOST_SETTINGS . $settingPath . 'title');

        if ($title === null) {
            $title = __(substr($settingPath, 0, strlen($settingPath) - 1) . '_title');
        }

        return $title;
    }

    /**
     * Create price
     * Calculate price if multiple options are chosen
     *
     * @param $settingPath
     *
     * @return float
     */
    private function getPrice($settingPath): float
    {
        $basePrice = $this->myParcelHelper->getBasePrice();
        $settingFee = (float) $this->myParcelHelper->getConfigValue($settingPath . 'fee');

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
    private function getQuoteFromCardOrSession() {
        if ($this->quote->getQuoteId() != null &&
            $this->quote->getQuote() &&
            $this->quote->getQuote() instanceof Countable &&
            count($this->quote->getQuote())
        ){
            return $this->quote->getQuote();
        }

        return $this->session->getQuote();
    }
}
