<?php
/**
 * Set MyParcel Shipping methods
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Checkout;

use Magento\Quote\Model\Quote\Address\RateResult\Error;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Simplexml\Element;
use Magento\Ups\Helper\Config;
use Magento\Framework\Xml\Security;
use MyParcelNL\Magento\Helper\{
    Checkout, Data
};

class Carrier extends AbstractCarrierOnline implements CarrierInterface
{
    const CODE = 'mypa';
    protected $_code = self::CODE;
    protected $_request;
    protected $_result;
    protected $_baseCurrencyRate;
    protected $_xmlAccessRequest;
    protected $_localeFormat;
    protected $_logger;
    protected $configHelper;
    protected $_errors = [];
    protected $_isFixed = true;
    /**
     * @var Checkout
     */
    private $myParcelHelper;

    /**
     * Carrier constructor.
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param Security $xmlSecurity
     * @param \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory $rateFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory
     * @param \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Directory\Model\CountryFactory $countryFactory
     * @param \Magento\Directory\Model\CurrencyFactory $currencyFactory
     * @param \Magento\Directory\Helper\Data $directoryData
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry
     * @param \Magento\Framework\Locale\FormatInterface $localeFormat
     * @param Config $configHelper
     * @param Checkout $myParcelHelper
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\Locale\FormatInterface $localeFormat,
        Config $configHelper,
        Checkout $myParcelHelper,
        array $data = []
    ) {
        $this->_localeFormat = $localeFormat;
        $this->configHelper = $configHelper;
        $this->myParcelHelper = $myParcelHelper;
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
    }

    public function collectRates(RateRequest $request)
    {
        $result = $this->_rateFactory->create();
        $result = $this->addShippingMethods($result);
        return $result;
    }

    public function proccessAdditionalValidation(\Magento\Framework\DataObject $request) {
        return true;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
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

    private function addShippingMethods($result)
    {
        foreach ($this->getAllowedMethods() as $alias => $settingPath) {
            $method = $this->getShippingMethod($alias, $settingPath);
            $result->append($method);
        }

        return $result;
    }


    /**
     * @param $alias
     * @param string $settingPath
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function getShippingMethod($alias, $settingPath)
    {

        $title = $this->createTitle($settingPath);
        $price = $this->createPrice($alias, $settingPath);

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($alias);
        $method->setMethod($alias);
        $method->setMethodTitle($title);
        $method->setPrice($price);

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
        if ($alias == 'morning_signature') {
            $price = $this->myParcelHelper->getMethodPrice('morning/fee') + $this->myParcelHelper->getMethodPrice('delivery/signature_fee');
        } else if ($alias == 'evening_signature') {
            $price = $this->myParcelHelper->getMethodPrice('evening/fee') + $this->myParcelHelper->getMethodPrice('delivery/signature_fee');
        } else {
            $price = $this->myParcelHelper->getMethodPrice($settingPath . 'fee');
        }

        return $price;
    }
}
