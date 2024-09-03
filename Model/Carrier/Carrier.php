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
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Carrier;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Model\Session;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\ErrorFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Magento\Shipping\Model\Tracking\ResultFactory;
use MyParcelNL\Magento\Helper\Checkout;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    const CODE = 'myparcelnl_delivery';

    protected $_code = self::CODE;
    protected $_freeShipping;

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

    /**
     * Carrier constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface          $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory  $rateErrorFactory
     * @param \Psr\Log\LoggerInterface                                    $logger
     * @param Security                                                    $xmlSecurity
     * @param \Magento\Shipping\Model\Simplexml\ElementFactory            $xmlElFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory                  $rateFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory              $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory        $trackErrorFactory
     * @param \Magento\Shipping\Model\Tracking\Result\StatusFactory       $trackStatusFactory
     * @param \Magento\Directory\Model\RegionFactory                      $regionFactory
     * @param \Magento\Directory\Model\CountryFactory                     $countryFactory
     * @param \Magento\Directory\Model\CurrencyFactory                    $currencyFactory
     * @param \Magento\Directory\Helper\Data                              $directoryData
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface        $stockRegistry
     * @param \Magento\Checkout\Model\Session                             $session
     * @param Checkout                                                    $myParcelHelper
     * @param PackageRepository                                           $package
     * @param array                                                       $data
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        Security $xmlSecurity,
        ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        MethodFactory $rateMethodFactory,
        ResultFactory $trackFactory,
        ErrorFactory $trackErrorFactory,
        StatusFactory $trackStatusFactory,
        RegionFactory $regionFactory,
        CountryFactory $countryFactory,
        CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        StockRegistryInterface $stockRegistry,
        Session $session,
        Checkout $myParcelHelper,
        PackageRepository $package,
        \Magento\OfflineShipping\Model\Carrier\Freeshipping $freeShipping,
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $data, // remove this line when you extend AbstractCarrierOnline, add it for AbstractCarrier
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
        $this->quote = $session->getQuote();
        $this->myParcelHelper = $myParcelHelper;
        $this->package = $package;
        $this->_rateFactory = $rateFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_freeShipping = $freeShipping;
    }

    protected function _doShipmentRequest(DataObject $request)
    {
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }
        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        // todo: get actual title based on chosen and possible options for this quote / cart
        $method->setCarrier($this->_code);
        $method->setCarrierTitle('MyParcel');

        $method->setMethod($this->_code);
        $method->setMethodTitle('Todo: chosen options here');

        $freeShippingIsAvailable = false; // todo // $this->_freeShipping->getConfigData('active')
        // todo: get actual price based on chosen and possible options for this quote / cart
        $amount = $freeShippingIsAvailable ? '0.00' : '10.00';

        $method->setPrice($amount);
        $method->setCost($amount);

        //$result->append($method);
        $result = $this->addShippingMethods($result);
//$bla = $this->quote->getCheckoutMethod();
        return $result;
        /** @var \Magento\Quote\Model\Quote\Address\RateRequest $result */
        $result = $this->_rateFactory->create();
        $result = $this->addShippingMethods($result);

        return $result;
    }

    public function proccessAdditionalValidation(DataObject $request)
    {
        return true;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public static function getMethods()
    {
        $methods = [
            'signature_only_recip' => 'delivery/signature_and_only_recipient_',
            'morning'              => 'morning/',
            'morning_signature'    => 'morning_signature/',
            'evening'              => 'evening/',
            'evening_signature'    => 'evening_signature/',
            'pickup'               => 'pickup/',
            'mailbox'              => 'mailbox/',
            'digital_stamp'        => 'digital_stamp/',
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
        return self::getMethods();
    }

    /**
     * @param \Magento\Quote\Model\Quote\Address\RateRequest $result
     * @return mixed
     */
    private function addShippingMethods($result)
    {
        $this->package->setDigitalStampSettings();
        $this->package->setMailboxSettings();

        foreach ($this->getAllowedMethods() as $alias => $settingPath) {
            $active = $this->myParcelHelper->getConfigValue(Data::XML_PATH_POSTNL_SETTINGS . $settingPath . 'active') === '1';
            if ($active) {
                $method = $this->getShippingMethod($alias, $settingPath);
                $result->append($method);
            }
        }

        return $result;
    }

    /**
     * @param $alias
     * @param  string $settingPath
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function getShippingMethod($alias, string $settingPath)
    {
        $title = $this->createTitle($settingPath);
        $price = $this->createPrice($alias, $settingPath);

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
        $title = $this->myParcelHelper->getConfigValue(Data::XML_PATH_POSTNL_SETTINGS . $settingPath . 'title');

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
    private function createPrice($alias, $settingPath)
    {
        if ($this->_freeShipping->getConfigData('active')) {
            return 0;
        }

        return 10 + $this->myParcelHelper->getMethodPrice($settingPath . 'fee', $alias);
    }

    public function isTrackingAvailable(): bool
    {
        // TODO: Implement isTrackingAvailable() method.
        return true;
    }
}
