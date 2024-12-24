<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use Magento\Quote\Api\ShippingMethodManagementInterface;

/**
 * Use this trait when you need to get the quote independently of session and have easy access to its properties
 */
trait NeedsQuoteProps
{
    protected AbstractDeliveryOptionsAdapter $deliveryOptions;

    protected function getQuoteFromRateRequest(RateRequest $request): ?Quote
    {
        /**
         * Do not use checkoutSession->getQuote()!!! it will cause infinite loop for
         * quotes with trigger_recollect = 1, see Quote::_afterLoad()
         * https://magento.stackexchange.com/questions/340048/how-to-properly-get-current-quote-in-carrier-collect-rates-function
         */
        $items = $request->getAllItems();
        if (empty($items)) {
            return null;
        }

        /** @var \Magento\Quote\Model\Quote\Item $firstItem */
        $firstItem = reset($items);
        if (!$firstItem) {
            return null;
        }

        $quote = $firstItem->getQuote();
        if (!($quote instanceof Quote)) {
            return null;
        }

        return $quote;
    }

    public function getQuoteFromCurrentSession(): ?Quote
    {
        $checkoutSession = ObjectManager::getInstance()->get(Session::class);
        $quote = $checkoutSession->getQuote();

        if (!($quote instanceof Quote)) {
            return null;
        }

        return $quote;
    }

    protected function getDeliveryOptionsFromQuote(Quote $quote): AbstractDeliveryOptionsAdapter
    {
        if (isset($this->deliveryOptions)) {
            return $this->deliveryOptions;
        }

        $do = $quote->getData(Config::FIELD_DELIVERY_OPTIONS);

        if (is_string($do)) {
            try {
                $this->deliveryOptions = DeliveryOptionsAdapterFactory::create(json_decode($do, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                Logger::log('warning', 'Failed to retrieve delivery options from quote ' . $quote->getId(), (array)$do);
                $this->deliveryOptions = new DeliveryOptionsV3Adapter();
            }
        } else {
            $this->deliveryOptions = new DeliveryOptionsV3Adapter();
        }

        return $this->deliveryOptions;
    }

    /**
     * Get shipping method array [method_title => amount] from quote
     *
     * @param Quote $quote
     * @return array Associative array by method title holding the amount for that method
     * @throws LocalizedException
     */
    public function getShippingMethodsFromQuote(Quote $quote): array
    {
        $quoteId = $quote->getId();
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', 'quoteId: ' . var_export($quoteId, true) . "\n", FILE_APPEND);

        try {
            $shippingMethodManagement = ObjectManager::getInstance()->get(ShippingMethodManagementInterface::class);
            $getShippingMethods = $shippingMethodManagement->getList($quoteId);

            $methods = [];
            foreach ($getShippingMethods as $method) {
                $methods[$method->getMethodTitle()] = $method->getAmount();
            }
        } catch (StateException $stateException) {
            throw new LocalizedException(__($stateException->getMessage()));
        } catch (\Exception $exception) {
            throw new LocalizedException(__($exception->getMessage()));
        }

        return $methods;
    }

    /**
     * If free shipping is available for this quote, will return true.
     *
     * @param Quote $quote
     * @return bool
     */
    public function isFreeShippingAvailable(Quote $quote): bool
    {
        try {
            $shippingMethods = $this->getShippingMethodsFromQuote($quote);
file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', 'shippingMethods: ' . var_export($shippingMethods, true) . "\n", FILE_APPEND);
            // loop through the methods to see if one is free (price = 0)
            foreach ($shippingMethods as $methodName => $methodAmount) {
                if (0.0 === $methodAmount) {
                    return true;
                }
            }
        } catch (LocalizedException $e) {
            Logger::critical($e->getMessage());
        }

        return false;
    }
}
