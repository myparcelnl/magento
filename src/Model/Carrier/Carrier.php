<?php

declare(strict_types=1);

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

use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\ShipmentOptionsV3Adapter;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Carrier\CarrierUPSStandard;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    use NeedsQuoteProps;

    public const CODE = 'myparcel'; // same as in /etc/config.xml and the carrier group in system.xml

    protected $_code = self::CODE; // $_code is a mandatory property for a Magento carrier
    protected $_name;
    protected $_title;

    public const ALLOWED_CARRIER_CLASSES = [
        CarrierPostNL::class,
        CarrierDHLForYou::class,
        CarrierDHLEuroplus::class,
        CarrierDHLParcelConnect::class,
        CarrierUPSStandard::class,
        CarrierDPD::class,
    ];

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
        if (! $this->getConfigFlag('active')) {
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
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getMethodTitle($quote));
        $method->setPrice((string) $this->getMethodAmount($quote));

        $result->append($method);

        return $result;
    }

    public function getMethodForFrontend(Quote $quote): array
    {
        $amount = $this->getMethodAmount($quote);

        return [
            'amount'         => $amount,
            'available'      => true,
            'base_amount'    => $amount,
            'carrier_code'   => $this->_code,
            'carrier_title'  => $this->_title,
            'error_message'  => '',
            'method_code'    => $this->_code,
            'method_title'   => $this->getMethodTitle($quote),
            'price_excl_tax' => $this->tax->excludingVat($amount, $quote),
            'price_incl_tax' => $this->tax->includingVat($amount, $quote),
        ];
    }

    private function getMethodAmount(Quote $quote): float
    {
        $deliveryOptions = $this->getDeliveryOptionsFromQuote($quote);
        $configPath      = Config::CARRIERS_XML_PATH_MAP[$deliveryOptions->getCarrier()] ?? '';
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? new ShipmentOptionsV3Adapter([]);
        $shipmentFees    = [
            "{$deliveryOptions->getDeliveryType()}/fee" => true,
            'delivery/only_recipient_fee'               => $shipmentOptions->hasOnlyRecipient(),
            'delivery/signature_fee'                    => $shipmentOptions->hasSignature(),
            'delivery/receipt_code_fee'                 => $shipmentOptions->hasReceiptCode(),
        ];

        $amount = $this->deliveryCosts->getBasePrice($quote);

        foreach ($shipmentFees as $key => $value) {
            if (! $value) {
                continue;
            }
            $amount += (float) $this->config->getConfigValue("$configPath$key");
        }

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
        } catch (\Throwable $e) {
            $carrierHuman = $carrierName;
        }

        ob_start();
        echo $carrierHuman, ' ', __("{$deliveryOptions->getDeliveryType()}_title"), ', ', __("{$deliveryOptions->getPackageType()}_title");


        foreach ($shipmentOptions->toArray() as $key => $value) {
            if ($value) {
                echo ', ', __("{$key}_title");
            }
        }

        return trim(ob_get_clean());
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
        return [$this->_code => $this->_name];
    }

    public function isTrackingAvailable(): bool
    {
        // TODO: Implement isTrackingAvailable() method.
        return true;
    }
}
