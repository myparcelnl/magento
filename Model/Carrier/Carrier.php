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

use Exception;
use http\Exception\InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Phrase;
use Magento\OfflineShipping\Model\Carrier\Freeshipping;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\ShipmentOptionsV3Adapter;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    use NeedsQuoteProps;

    public const CODE = 'myparcel'; // same as in /etc/config.xml

    protected $_code = self::CODE; // $_code is a mandatory property for a Magento carrier
    protected $_name;
    protected $_title;
    protected $_freeShipping;


    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * Carrier constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param Config $config
     * @param DeliveryCosts $deliveryCosts
     * @param ResultFactory $rateFactory
     * @param MethodFactory $rateMethodFactory
     * @param PackageRepository $package
     * @param Freeshipping $freeShipping
     * @param array $data
     *
     * @throws Exception
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        Config               $config,
        DeliveryCosts        $deliveryCosts,
        ResultFactory        $rateFactory,
        MethodFactory        $rateMethodFactory,
        PackageRepository    $package,
        Freeshipping         $freeShipping,
        array                $data = []
    )
    {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $data,
        );

        $this->_name = $config->getMagentoCarrierConfig('name') ?: self::CODE;
        $this->_title = $config->getMagentoCarrierConfig('title') ?: self::CODE;

        $this->config = $config;
        $this->package = $package;
        $this->rateResultFactory = $rateFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->_freeShipping = $freeShipping;
        $this->deliveryCosts = $deliveryCosts;
    }

    protected function _doShipmentRequest(DataObject $request)
    {
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $quote = $this->getQuoteFromRateRequest($request);

        if (null === $quote) {
            throw new InvalidArgumentException('No quote found in request');
        }

        $result = $this->rateResultFactory->create();
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->_title);
        $method->setMethod($this->_name);
        $method->setMethodTitle($this->getMethodTitle($quote));
        $method->setPrice((string)$this->getMethodAmount($quote));

        $result->append($method);

        return $result;
    }

    private function getMethodAmount(Quote $quote): float
    {
        $deliveryOptions = $this->getDeliveryOptionsFromQuote($quote);
        $configPath = Config::CARRIERS_XML_PATH_MAP[$deliveryOptions->getCarrier()] ?? '';
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? new ShipmentOptionsV3Adapter([]);
        $shipmentFees = [
            "{$deliveryOptions->getDeliveryType()}/fee" => true,
            //"{$this->deliveryOptions->getPackageType()}/fee"  => true,
            'delivery/signature_fee' => $shipmentOptions->hasSignature(),
            'delivery/only_recipient_fee' => $shipmentOptions->hasOnlyRecipient(),
        ];
        $amount = $this->deliveryCosts->getBasePrice($quote);
        foreach ($shipmentFees as $key => $value) {
            if (!$value) {
                continue;
            }
            $amount += (float)$this->config->getConfigValue("$configPath$key");
        }

        return $amount;
        //$this->configService->getMethodPrice(ConfigService::XML_PATH_POSTNL_SETTINGS . 'fee', $alias);
        $freeShippingIsAvailable = false; // todo find out if this order has free shipping // $this->_freeShipping->getConfigData('active')
        // todo: get actual price based on chosen and possible options for this quote / cart
        $amount = $freeShippingIsAvailable ? 0.00 : 10.00;
        return $amount;
    }

    private function getMethodTitle(Quote $quote): string
    {
        $d = $this->getDeliveryOptionsFromQuote($quote);
        $s = $d->getShipmentOptions() ?? new ShipmentOptionsV3Adapter([]);

        ob_start();
        echo __("{$d->getDeliveryType()}_title"), ' ';

        foreach ($s->toArray() as $key => $value) {
            if ($value) {
                echo __("{$key}_title"), ' ';
            }
        }

        return ob_get_clean();
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
    public function getAllowedMethods(): array
    {
        return [$this->_name];
    }

    public function isTrackingAvailable(): bool
    {
        // TODO: Implement isTrackingAvailable() method.
        return true;
    }

    /**
     * @param $alias
     * @param string $settingPath
     *
     * @return Method
     */
    private function getShippingMethod($alias, string $settingPath)
    {
        $title = $this->createTitle($settingPath);
        $price = $this->createPrice($alias, $settingPath);

        $method = $this->rateMethodFactory->create();
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
     * @return Phrase|mixed
     */
    private function createTitle($settingPath)
    {
        $title = $this->config->getConfigValue(Config::XML_PATH_POSTNL_SETTINGS . "{$settingPath}title");

        if ($title === null) {
            $title = __("{$settingPath}title");
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

        return 10 + $this->config->getFloatConfig("{$settingPath}fee", $alias);
    }
}
