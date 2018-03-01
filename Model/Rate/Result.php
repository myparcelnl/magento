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

use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use Magento\Checkout\Model\Session;

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
	 * @param \Magento\Backend\Model\Session\Quote $quote
	 * @param Checkout $myParcelHelper
	 * @param Session $session
	 * @param PackageRepository $package
	 *
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
        $this->package = $package;
	    $this->session = $session;
	    $this->quote = $quote;

        $this->parentMethods = explode(',', $this->myParcelHelper->getCheckoutConfig('general/shipping_methods', true));
	    $this->products = $this->getProductsFromCardAndSession();
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
    private function getMethods()
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
    private function getAllowedMethods()
    {
	    if ($this->package->fitInMailbox() && $this->package->isShowMailboxWithOtherOptions() === false) {
		    $methods = ['mailbox' => 'mailbox/'];

		    return $methods;
	    }

	    $methods = $this->getMethods();

	    if (!$this->package->fitInMailbox()) {
		    unset($methods['mailbox']);
	    }

	    return $methods;
    }

    /**
     * Add Myparcel shipping rates
     *
     * @param $parentRate \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function addMyParcelRates($parentRate)
    {
        if ($this->myParcelRatesAlreadyAdded) {
            return;
        }

        $currentCarrier = $parentRate->getData('carrier');
        if (!in_array($currentCarrier, $this->parentMethods)) {
            return;
        }

        $this->package->setMailboxSettings();

        if (count($this->products) > 0){
            $this->package->setWeightFromQuoteProducts($this->products);
        }

        foreach ($this->getAllowedMethods() as $alias => $settingPath) {
            $settingActive = $this->myParcelHelper->getConfigValue(Data::XML_PATH_CHECKOUT . $settingPath . 'active');
            $active = $settingActive === '1' || $settingActive === null;
            if ($active) {
                $method = $this->getShippingMethod($alias, $settingPath, $parentRate);
                $this->append($method);
            }
        }

        $this->myParcelRatesAlreadyAdded = true;
    }

    /**
     * @param $alias
     * @param string $settingPath
     * @param $parentRate \Magento\Quote\Model\Quote\Address\RateResult\Method
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function getShippingMethod($alias, $settingPath, $parentRate)
    {
        $method = clone $parentRate;
        $this->myParcelHelper->setBasePrice($parentRate->getPrice());

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
            $price += $this->myParcelHelper->getMethodPrice('delivery/signature_fee', false);

            return $price;
        }

        if ($alias == 'evening_signature') {
            $price += $this->myParcelHelper->getMethodPrice('evening/fee');
            $price += $this->myParcelHelper->getMethodPrice('delivery/signature_fee', false);

            return $price;
        }

        $price += $this->myParcelHelper->getMethodPrice($settingPath . 'fee', $alias !== 'mailbox');

        return $price;
    }

	/**
	 * Can't get quote from session\Magento\Checkout\Model\Session::getQuote()
	 * To fix a conflict with buckeroo, use \Magento\Checkout\Model\Cart::getQuote() like the following
	 */
	private function getProductsFromCardAndSession() {
		if (count($this->quote->getQuote()->getItems())) {
			return $this->quote->getQuote()->getItems();
		}

		return $this->session->getQuote()->getitems();
	}
}