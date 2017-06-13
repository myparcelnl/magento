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

class Checkout extends Data
{
    private $base_price = 0;

    private $tmp_scope = null;

    /**
     * @return int
     */
    public function getBasePrice(): int
    {
        return $this->base_price;
    }

    /**
     * @param int $base_price
     */
    public function setBasePrice(int $base_price)
    {
        $this->base_price = $base_price;
    }

    /**
     * Set shipping base price
     *
     * @param \Magento\Quote\Model\Quote $quote
     *
     * @return Checkout
     */
    public function setBasePriceFromQuote($quote)
    {
        $address = $quote->getShippingAddress();

        $address->requestShippingRates();
        $rate = $address->getShippingRateByCode('flatrate_flatrate');
        $this->setBasePrice((int)$rate->getPrice());

        return $this;
    }

    /**
     * @param \Magento\Quote\Model\Quote $quote
     *
     * @return string
     */
    public function getParentRateFromQuote($quote)
    {
        $parentMethods = explode(',', $this->getCheckoutConfig('shipping_methods'));
        foreach ($quote->getShippingAddress()->getShippingRatesCollection() as $rate) {
            if (in_array($rate->getData('carrier'), $parentMethods)) {
                return $rate->getData('carrier');
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
        return (float)Mage::helper('tax')->getShippingPrice($price, $flag, $quote->getShippingAddress());
    }*/

    /**
     * @return mixed
     */
    public function getTmpScope()
    {
        return $this->tmp_scope;
    }

    /**
     * @param mixed $tmp_scope
     */
    public function setTmpScope($tmp_scope)
    {
        $this->tmp_scope = $this->getConfigValue(self::XML_PATH_CHECKOUT . $tmp_scope);
        if (!is_array($this->tmp_scope)) {
            $this->logger->critical('Can\'t get settings with path:' . self::XML_PATH_CHECKOUT . $tmp_scope);
        }
    }

    /**
     * Get checkout setting
     *
     * @param string $code
     * @param null   $storeId
     *
     * @return mixed
     */
    public function getCheckoutConfig($code, $storeId = null)
    {
        $settings = $this->getTmpScope();
        if ($settings == null) {
            $value = $this->getConfigValue(self::XML_PATH_CHECKOUT . $code);
            if ($value != null) {
                return $value;
            } else {
                $this->logger->critical('Can\'t get setting with path:' . self::XML_PATH_CHECKOUT . $code);
            }
        }

        if (!is_array($settings)) {
            $this->logger->critical('No data in settings array');
        }

        if (!key_exists($code, $settings)) {
            $this->logger->critical('Can\'t get setting ' . $code);
        }

        return $settings[$code];
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