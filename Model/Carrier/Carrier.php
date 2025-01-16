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
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\ShipmentOptionsV3Adapter;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    use NeedsQuoteProps;

    public const CODE = 'myparcel'; // same as in /etc/config.xml and the carrier group in system.xml

    protected $_code = self::CODE; // $_code is a mandatory property for a Magento carrier
    protected $_name;
    protected $_title;

    /**
     * Carrier constructor.
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory         $rateErrorFactory
     * @param LoggerInterface      $logger
     * @param Tax                  $tax
     * @param Config               $config
     * @param DeliveryCosts        $deliveryCosts
     * @param ResultFactory        $rateFactory
     * @param MethodFactory        $rateMethodFactory
     * @param array                $data
     *
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory         $rateErrorFactory,
        LoggerInterface      $logger,
        Tax                  $tax,
        Config               $config,
        DeliveryCosts        $deliveryCosts,
        ResultFactory        $rateFactory,
        MethodFactory        $rateMethodFactory,
        array                $data = []
    )
    {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $data,
        );

        $this->_name  = $config->getMagentoCarrierConfig('name') ?: self::CODE;
        $this->_title = $config->getMagentoCarrierConfig('title') ?: self::CODE;

        $this->tax               = $tax;
        $this->config            = $config;
        $this->rateResultFactory = $rateFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->deliveryCosts     = $deliveryCosts;
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
        $method->setPrice((string) $this->getMethodAmountExcludingVat($quote));

        $result->append($method);

        return $result;
    }

    public function getMethodForFrontend(Quote $quote): array
    {
        // Magento checkout needs the price ex vat for displaying in the cart summary, bypassing the admin settings
        $amount = $this->getMethodAmountExcludingVat($quote);

        //todo joeri where is this specific structure / array coming from? Not method->toArray unfortunately
        return [
            'amount'         => $amount,
            'available'      => true,
            'base_amount'    => $amount,
            'carrier_code'   => $this->_code,
            'carrier_title'  => $this->_title,
            'error_message'  => '',
            'method_code'    => $this->_name,
            'method_title'   => $this->getMethodTitle($quote),
            //todo JOERI where is excl / incl vat ever used? Some custom checkout maybe?
            'price_excl_tax' => $amount,
            'price_incl_tax' => $this->tax->addVatToExVatPrice($amount, $quote),
        ];
    }

    private function getMethodAmountExcludingVat(Quote $quote): float
    {
        $deliveryOptions = $this->getDeliveryOptionsFromQuote($quote);
        $configPath      = Config::CARRIERS_XML_PATH_MAP[$deliveryOptions->getCarrier()] ?? '';
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? new ShipmentOptionsV3Adapter([]);
        $shipmentFees    = [
            "{$deliveryOptions->getDeliveryType()}/fee" => true,
            //"{$deliveryOptions->getPackageType()}/fee"  => true,
            'delivery/only_recipient_fee'               => $shipmentOptions->hasOnlyRecipient(),
            'delivery/signature_fee'                    => $shipmentOptions->hasSignature(),
            'delivery/receipt_code_fee'                 => $shipmentOptions->hasReceiptCode(),
        ];

        $amount = $this->tax->excludingVat($this->deliveryCosts->getBasePrice($quote), $quote);

        foreach ($shipmentFees as $key => $value) {
            if (!$value) {
                continue;
            }
            $amount += $this->tax->excludingVat((float) $this->config->getConfigValue("$configPath$key"), $quote);
        }
        //file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', 'AMOUNT (JOERIDEBUG): ' . var_export($amount, true) . "\n", FILE_APPEND);

        // the method should never give a discount on the order, so we return 0 if the amount is negative
        return max(0, $amount);
    }

    private function getMethodTitle(Quote $quote): string
    {
        $deliveryOptions = $this->getDeliveryOptionsFromQuote($quote);
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? new ShipmentOptionsV3Adapter([]);
        $carrierName     = $deliveryOptions->getCarrier();

        if (null === $carrierName) {
            return $this->_title;
        }

        try {
            $carrierHuman = CarrierFactory::createFromName($carrierName)->getHuman();
        } catch (Exception $e) {
            $carrierHuman = $carrierName;
        }

        ob_start();
        echo $carrierHuman, ' ', __("{$deliveryOptions->getDeliveryType()}_title"), ', ';

        foreach ($shipmentOptions->toArray() as $key => $value) {
            if ($value) {
                echo trim(__("{$key}_title")), ', ';
            }
        }

        return substr(trim(ob_get_clean()), 0, -1); // remove trailing comma
    }

    public function processAdditionalValidation(DataObject $request): bool
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
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', "CreatePrice is called on Carrier\n", FILE_APPEND);

        return 10.21;
    }
}
