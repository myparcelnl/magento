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
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Rate;

use Magento\Ups\Helper\Config;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Helper\{
    Checkout, Data
};

class Result extends \Magento\Shipping\Model\Rate\Result
{
    protected $_localeFormat;
    protected $configHelper;
    protected $_isFixed = true;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var Checkout
     */
    private $myParcelHelper;

    /**
     * @var PackageRepository
     */
    private $package;

    private $parentRate;

    /**
     * Result constructor.
     * @param \Magento\Checkout\Model\Session $session
     * @param Config $configHelper
     * @param Checkout $myParcelHelper
     * @param PackageRepository $package
     */
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Checkout\Model\Session $session,
        Config $configHelper,
        Checkout $myParcelHelper,
        PackageRepository $package)
    {
        parent::__construct($storeManager);
        $this->quote = $session->getQuote();
        $this->configHelper = $configHelper;
        $this->myParcelHelper = $myParcelHelper;
        $this->package = $package;
    }

    /**
     * Add a rate to the result
     *
     * @param \Magento\Quote\Model\Quote\Address\RateResult\AbstractResult|\Magento\Shipping\Model\Rate\Result $result
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

                $currentCarrier = $rate->getData('carrier');
                if ($currentCarrier == 'flatrate') {
                    $this->parentRate = $rate;
                    $this->addShippingRates();
                }
            }

        }

        return $this;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getMethods()
    {
        $methods = [
            'signature' => 'delivery/signature_',
            'only_recipient' => 'delivery/only_recipient_',
            'signature_only_recip' => 'delivery/signature_and_only_recipient_',
            'morning' => 'morning/',
            'morning_signature' => 'morning_signature/',
            'evening' => 'evening/',
            'evening_signature' => 'evening_signature/',
            'pickup' => 'pickup/',
            'pickup_express' => 'pickup_express/',
            'mailbox' => 'mailbox/',
        ];

        return $methods;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        $methods = $this->getMethods();

        if ($this->package->fitInMailbox() && $this->package->isShowMailboxWithOtherOptions() === false) {
            $methods = ['mailbox' => 'mailbox/'];
        } else if (!$this->package->fitInMailbox()) {
            unset($methods['mailbox']);
        }

        return $methods;
    }

    /**
     * Add Myparcel shipping rates
     */
    private function addShippingRates()
    {
        $price = 20;

        $products = $this->quote->getAllItems();
        if (count($products) > 0){
            $this->package->setWeightFromQuoteProducts($products);
        }
        $this->package->setMailboxSettings();

        foreach ($this->getAllowedMethods() as $alias => $settingPath) {

            $active = $this->myParcelHelper->getConfigValue(Data::XML_PATH_CHECKOUT . $settingPath . 'active') === '1';
            if ($active) {
                $method = $this->getShippingMethod($alias, $settingPath);
                $this->append($method);
            }
        }
    }

    /**
     * @param $alias
     * @param string $settingPath
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function getShippingMethod($alias, $settingPath)
    {

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = clone $this->parentRate;
        $this->myParcelHelper->setBasePrice($this->parentRate->getPrice());

        $title = $this->createTitle($settingPath);
        $price = $this->createPrice($alias, $settingPath);
        $method->setCarrierTitle($alias);
        $method->setMethod($alias);
        $method->setMethodTitle($title);
        $method->setPrice($price);
        $method->setCost(0);

        return $method;
    }

    /**
     * Create title for method
     * If no title isset in config, get title from translation
     *
     * @param $settingPath
     * @return \Magento\Framework\Phrase|mixed
     */
    private function createTitle($settingPath)
    {
        $title = $this->myParcelHelper->getConfigValue(Data::XML_PATH_CHECKOUT . $settingPath . 'title');

        if ($title === null) {
            $title = __($settingPath . 'title');
        }

        return $title;
    }

    /**
     * Create price
     * Calculate price if multiple options are chosen
     *
     * @param $alias
     * @param $settingPath
     * @return float
     */
    private function createPrice($alias, $settingPath) {
        $price = 0;
        if ($alias == 'morning_signature') {
            $price += $this->myParcelHelper->getMethodPrice('morning/fee');
            $price += $this->myParcelHelper->getMethodPrice('delivery/signature_fee');
        } else if ($alias == 'evening_signature') {
            $price += $this->myParcelHelper->getMethodPrice('evening/fee');
            $price += $this->myParcelHelper->getMethodPrice('delivery/signature_fee');
        } else {
            $price += $this->myParcelHelper->getMethodPrice($settingPath . 'fee', $alias !== 'mailbox');
        }

        return $price;
    }
}