<?php
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Quote\Api\Data\EstimateAddressInterfaceFactory;
use Magento\Quote\Model\ShippingMethodManagement;
use MyParcelNL\Sdk\src\Services\CheckApiKeyService;

class Checkout extends Data
{
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
     * @param Context $context
     * @param ModuleListInterface $moduleList
     * @param EstimateAddressInterfaceFactory $estimatedAddressFactory
     * @param ShippingMethodManagement $shippingMethodManagement
     * @param CheckApiKeyService $checkApiKeyService
     */
    public function __construct(
        Context $context,
        ModuleListInterface $moduleList,
        EstimateAddressInterfaceFactory $estimatedAddressFactory,
        ShippingMethodManagement $shippingMethodManagement,
        CheckApiKeyService $checkApiKeyService
    )
    {
        parent::__construct($context, $moduleList, $checkApiKeyService);
        $this->shippingMethodManagement = $shippingMethodManagement;
        $this->estimatedAddressFactory = $estimatedAddressFactory;
    }

    /**
     * @return float
     */
    public function getBasePrice()
    {
        return $this->base_price;
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
        $this->setBasePrice((double)$price);

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
     * @return string
     */
    public function getParentMethodNameFromQuote($quoteId)
    {
        $method = $this->getParentRateFromQuote($quoteId);
        if ($method === null) {
            return null;
        }

        return $method->getMethodCode();
    }

    /**
     * @param \Magento\Quote\Model\Quote $quoteId
     *
     * @return string
     */
    public function getParentCarrierNameFromQuote($quoteId)
    {
        $method = $this->getParentRateFromQuote($quoteId);
        if ($method === null) {
            return null;
        }

        return $method->getCarrierCode();
    }

    /**
     * @param \Magento\Quote\Model\Quote $quoteId
     *
     * @return \Magento\Quote\Model\Cart\ShippingMethod $methods
     */
    public function getParentRateFromQuote($quoteId)
    {
        if ($quoteId == null) {
            return null;
        }

        $parentMethods = explode(',', $this->getCheckoutConfig('general/shipping_methods'));

        /**
         * @var \Magento\Quote\Api\Data\EstimateAddressInterface $estimatedAddress
         * @var \Magento\Quote\Model\Cart\ShippingMethod[] $methods
         */
        $estimatedAddress = $this->estimatedAddressFactory->create();
        $estimatedAddress->setCountryId('NL');
        $estimatedAddress->setPostcode('');
        $estimatedAddress->setRegion('');
        $estimatedAddress->setRegionId('');
        $methods = $this->shippingMethodManagement->estimateByAddress($quoteId, $estimatedAddress);

        foreach ($methods as $method) {
            if (in_array($method->getCarrierCode(), $parentMethods)) {
                return $method;
            }
        }

        return null;
    }

    /**
     * Get MyParcel method/option price.
     *
     *Check if total shipping price is not below 0 euro
     *
     * @param string $key
     * @param bool $addBasePrice
     *
     * @return float
     */
    public function getMethodPrice($key, $addBasePrice = true)
    {
        $value = $this->getCheckoutConfig($key);

        if ($addBasePrice) {
            if ($this->getBasePrice() + $value < 0) {
                return (float)0;
            }

            // Calculate value
            $value = $this->getBasePrice() + $value;
        }

        return (float)$value;
    }

    /**
     * Get MyParcel method/option price with EU format
     *
     * @param string $key
     * @param bool $addBasePrice
     * @param string $prefix
     *
     * @return string
     */
    public function getMethodPriceFormat($key, $addBasePrice = true, $prefix = '')
    {
        $value = $this->getMethodPrice($key, $addBasePrice);
        $value = $this->getMoneyFormat($value);
        $value = $prefix . $value;

        return $value;
    }

    /**
     * Get price in EU format
     *
     * @param float $value
     *
     * @return string
     */
    public function getMoneyFormat($value) {
        $value = number_format($value, 2, ',', '.');
        $value = '&#8364; ' . (string)$value;

        return $value;
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
     * @param string $code
     * @param bool $canBeNull
     *
     * @return mixed
     */
    public function getCheckoutConfig($code, $canBeNull = false)
    {
        $value = $this->getConfigValue(self::XML_PATH_CHECKOUT . $code);
        if (null != $value || $canBeNull) {
            return $value;
        }

        $this->_logger->critical('Can\'t get setting with path:' . self::XML_PATH_CHECKOUT . $code);
    }

    /**
     * Get bool of setting
     *
     * @param string $key
     *
     * @return bool
     */
    public function getBoolConfig($key)
    {
        return $this->getCheckoutConfig($key) == "1" ? true : false;
    }

    /**
     * Get time for delivery endpoint
     *
     * @param string $key
     *
     * @return string
     */
    public function getTimeConfig($key)
    {
        return str_replace(',', ':', $this->getCheckoutConfig($key));
    }

    /**
     * Get array for delivery endpoint
     *
     * @param string $key
     *
     * @return string
     */
    public function getArrayConfig($key)
    {
        return str_replace(',', ';', $this->getCheckoutConfig($key));
    }

    /**
     * Get array for delivery endpoint
     *
     * @param string $key
     *
     * @return float
     */
    public function getIntergerConfig($key)
    {
        return (float)$this->getCheckoutConfig($key);
    }
}
