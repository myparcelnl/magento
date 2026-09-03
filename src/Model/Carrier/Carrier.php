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
use MyParcelNL\Magento\Adapter\DeliveryOptions\ShipmentOptions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\DeliveryCosts;
use MyParcelNL\Magento\Service\NeedsQuoteProps;
use MyParcelNL\Magento\Service\Tax;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    use NeedsQuoteProps;

    public const CODE = 'myparcel'; // same as in /etc/config.xml and the carrier group in system.xml

    protected $_code = self::CODE; // $_code is a mandatory property for a Magento carrier
    protected $_name;
    protected $_title;

    private const DELIVERY_TYPE_TITLES = [
        'standard' => 'Standard Delivery',
        'pickup'   => 'Pickup locations',
        'morning'  => 'Morning Delivery',
        'evening'  => 'Evening Delivery',
    ];

    private const PACKAGE_TYPE_TITLES = [
        'package'       => 'Package',
        'mailbox'       => 'Mailbox',
        'digital_stamp' => 'Digital stamp',
        'package_small' => 'Packet',
    ];

    private const SHIPMENT_OPTION_TITLES = [
        'signature'         => 'Signature',
        'only_recipient'    => 'Only recipient',
        'hide_sender'       => 'Hide sender',
        'priority_delivery' => 'Priority delivery',
        'receipt_code'      => 'Receipt code',
        'same_day_delivery' => 'Same day delivery',
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
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? ShipmentOptions::none();
        $shipmentFees    = [
            "{$deliveryOptions->getDeliveryType()}/fee" => true,
            'delivery/only_recipient_fee'               => $shipmentOptions->hasOnlyRecipient(),
            'delivery/signature_fee'                    => $shipmentOptions->hasSignature(),
            'delivery/receipt_code_fee'                 => $shipmentOptions->hasReceiptCode(),
            'mailbox/priority_delivery_fee'            => $shipmentOptions->hasPriorityDelivery(),
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
        $shipmentOptions = $deliveryOptions->getShipmentOptions() ?? ShipmentOptions::none();
        $carrierName     = $deliveryOptions->getCarrier();

        if (null === $carrierName || '0' === $this->config->getGeneralConfig('shipping_methods/show_details_in_summary')) {
            return $this->config->getMagentoCarrierConfig('name');
        }

        try {
            $carrierHuman = CarrierFactory::createFromName($carrierName)->getHuman();
        } catch (\Throwable $e) {
            $carrierHuman = $carrierName;
        }

        $deliveryTypeTitle = self::DELIVERY_TYPE_TITLES[$deliveryOptions->getDeliveryType()] ?? $deliveryOptions->getDeliveryType();
        $packageTypeTitle  = self::PACKAGE_TYPE_TITLES[$deliveryOptions->getPackageType()] ?? $deliveryOptions->getPackageType();

        ob_start();
        echo $carrierHuman, ' ', __($deliveryTypeTitle), ', ', __($packageTypeTitle);

        foreach ($shipmentOptions->toArray() as $key => $value) {
            if ($value && isset(self::SHIPMENT_OPTION_TITLES[$key])) {
                echo ', ', __(self::SHIPMENT_OPTION_TITLES[$key]);
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
