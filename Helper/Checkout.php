<?php

declare(strict_types=1);
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Helper;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Quote\Api\Data\EstimateAddressInterfaceFactory;
use Magento\Quote\Model\ShippingMethodManagement;
use MyParcelBE\Magento\Model\Rate\Result;
use MyParcelBE\Magento\Model\Source\PriceDeliveryOptionsView;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;

class Checkout extends Data
{
    public const FIELD_DROP_OFF_DAY     = 'drop_off_day';
    public const FIELD_MYPARCEL_CARRIER = 'myparcel_carrier';
    public const FIELD_DELIVERY_OPTIONS = 'myparcel_delivery_options';
    public const FIELD_TRACK_STATUS     = 'track_status';
    public const DEFAULT_COUNTRY_CODE   = 'BE';

    /**
     * @var int
     */
    private $base_price = 0;

    /**
     * @var ShippingMethodManagement
     */
    private $shippingMethodManagement;
    /**
     * @var EstimateAddressInterfaceFactory
     */
    private $estimatedAddressFactory;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @param Context                         $context
     * @param ModuleListInterface             $moduleList
     * @param EstimateAddressInterfaceFactory $estimatedAddressFactory
     * @param ShippingMethodManagement        $shippingMethodManagement
     * @param CheckApiKeyService              $checkApiKeyService
     * @param Session                         $session
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        EstimateAddressInterfaceFactory $estimatedAddressFactory,
        ShippingMethodManagement $shippingMethodManagement,
        CheckApiKeyService $checkApiKeyService,
        Session $session
    ) {
        parent::__construct($context, $moduleList, $checkApiKeyService);
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->estimatedAddressFactory  = $estimatedAddressFactory;
        $this->quote                    = $session->getQuote();
    }

    /**
     * @return float
     */
    public function getBasePrice()
    {
        return $this->base_price;
    }

    /**
     * @param  string $message
     *
     * @return void
     */
    public function log(string $message): void
    {
        $this->_logger->critical($message);
    }

    /**
     * @param float $base_price
     */
    public function setBasePrice($base_price)
    {
        $this->base_price = $base_price;
    }

    /**
     * Set shipping base price
     *
     * @param \Magento\Quote\Model\Quote $quoteId
     *
     * @return Checkout
     */
    public function setBasePriceFromQuote($quoteId)
    {
        $price = $this->getParentRatePriceFromQuote($quoteId);
        $this->setBasePrice((double) $price);

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote $quoteId
     *
     * @return string
     */
    public function getParentRatePriceFromQuote($quoteId)
    {
        $method = $this->getParentRateFromQuote($quoteId);
        if ($method === null) {
            return null;
        }

        return $method->getPriceInclTax();
    }

    /**
     * @param \Magento\Quote\Model\Quote $quoteId
     *
     * @return \Magento\Quote\Model\Cart\ShippingMethod|null
     */
    public function getParentRateFromQuote($quoteId, array $forAddress = [])
    {
        if (null === $quoteId) {
            return null;
        }

        $parentCarriers   = explode(',', $this->getGeneralConfig('shipping_methods/methods') ?? '');

        /**
         * @var \Magento\Quote\Api\Data\EstimateAddressInterface $estimatedAddress
         * @var \Magento\Quote\Model\Cart\ShippingMethod[]       $methods
         */
        $estimatedAddress = $this->getEstimatedAddress($forAddress, $this->quote->getShippingAddress());
        $magentoMethods  = $this->shippingMethodManagement->estimateByAddress($quoteId, $estimatedAddress);
        $myParcelMethods = array_keys(Result::getMethods());

        foreach ($magentoMethods as $method) {
            $methodCode       = explode('/', $method->getMethodCode() ?? '');
            $latestMethodCode = array_pop($methodCode);

            if (
                ! in_array($latestMethodCode, $myParcelMethods, true)
                && in_array($method->getCarrierCode(), $parentCarriers, true)
            ) {
                return $method;
            }
        }

        return null;
    }

    /**
     * @param array                              $fromClient
     * @param \Magento\Quote\Model\Quote\Address $fromQuote
     *
     * @return \Magento\Quote\Api\Data\EstimateAddressInterface
     */
    private function getEstimatedAddress(
        array $fromClient,
        \Magento\Quote\Model\Quote\Address $fromQuote
    ): \Magento\Quote\Api\Data\EstimateAddressInterface
    {
        $address = $this->estimatedAddressFactory->create();

        if (isset($fromClient['countryId'])) {
            $address->setCountryId($fromClient['countryId'] ?? self::DEFAULT_COUNTRY_CODE);
            $address->setPostcode($fromClient['postcode'] ??  '');
            $address->setRegion($fromClient['region'] ??  '');
        } else {
            $address->setCountryId($fromQuote->getCountryId() ?? self::DEFAULT_COUNTRY_CODE);
            $address->setPostcode($fromQuote->getPostcode() ?? '');
            $address->setRegion($fromQuote->getRegion() ?? '');
            $address->setRegionId($fromQuote->getRegionId() ?? 0);
        }

        return $address;
    }

    /**
     * Get MyParcel method/option price.
     * Check if total shipping price is not below 0 euro
     *
     * @param  string $carrier
     * @param  string $key
     * @param  bool   $addBasePrice
     *
     * @return float
     */
    public function getMethodPrice(string $carrier, string $key, bool $addBasePrice = true): float
    {
        $value = $this->getCarrierConfig($key, $carrier);
        $showTotalPrice   = $this->getCarrierConfig('shipping_methods/delivery_options_prices', Data::XML_PATH_GENERAL) === PriceDeliveryOptionsView::TOTAL;

        if ($showTotalPrice && $addBasePrice) {
            // Calculate value
            $value = $this->getBasePrice() + $value;
        }

        return (float) $value;
    }

    /**
     * Get shipping price
     *
     * @param $price
     * @param $flag
     *
     * @return mixed
     */
    /*private function getShippingPrice($price, $flag = false)
    {
        $flag = $flag ? true : Mage::helper('tax')->displayShippingPriceIncludingTax();
        return (float)Mage::helper('tax')->getShippingPrice($price, $flag, $quoteId->getShippingAddress());
    }*/

    /**
     * Get checkout setting
     *
     * @param  string $carrier
     * @param  string $code
     *
     * @return mixed
     */
    public function getCarrierConfig(string $code, string $carrier)
    {
        $value = $this->getConfigValue($carrier . $code);
        if (null === $value) {
            $this->_logger->critical('Can\'t get setting with path:' . $carrier . $code);
            return 0;
        }

        return $value;
    }

    /**
     * Get bool of setting
     *
     * @param  string $carrier
     * @param  string $key
     *
     * @return bool
     */
    public function getBoolConfig(string $carrier, string $key): bool
    {
        return '1' === $this->getCarrierConfig($key, $carrier);
    }

    /**
     * Get time for delivery endpoint
     *
     * @param string $carrier
     * @param string $key
     *
     * @return string
     */
    public function getTimeConfig(string $carrier, string $key): string
    {
        $timeAsString   = str_replace(',', ':', (string) $this->getCarrierConfig($key, $carrier));
        $timeComponents = explode(':', $timeAsString ?? '');
        if (count($timeComponents) >= 3) {
            [$hours, $minutes] = $timeComponents;
            $timeAsString = $hours . ':' . $minutes;
        }

        return $timeAsString;
    }

    /**
     * Get array for delivery endpoint
     *
     * @param string $carrier
     * @param string $key
     *
     * @return array
     */
    public function getArrayConfig(string $carrier, string $key): array
    {
        return array_map(static function($val) {
            return is_numeric($val) ? (int) $val : $val;
        }, explode(',', (string) ($this->getCarrierConfig($key, $carrier) ?? '')));
    }

    /**
     * Get array for delivery endpoint
     *
     * @param string $carrier
     * @param string $key
     *
     * @return float
     */
    public function getIntegerConfig($carrier, $key)
    {
        return (float) $this->getCarrierConfig($key, $carrier);
    }
}
