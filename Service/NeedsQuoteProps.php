<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use Magento\Quote\Api\ShippingMethodManagementInterface;

/**
 * Use this trait when you need to get the quote in several scenarios and have easy access to its properties.
 * During ‘raterequest’ the shipping methods are not always available, therefore we remember the availability of the
 * free shipping method in a session variable.
 *
 * @property AbstractDeliveryOptionsAdapter $deliveryOptions
 */
trait NeedsQuoteProps
{
    protected AbstractDeliveryOptionsAdapter $deliveryOptions;

    /**
     * Use this method during raterequest, because getting it from there session will cause infinite loop for
     * quotes with trigger_recollect = 1, see Quote::_afterLoad()
     * https://magento.stackexchange.com/questions/340048/how-to-properly-get-current-quote-in-carrier-collect-rates-function
     */
    protected function getQuoteFromRateRequest(RateRequest $request): ?Quote
    {
        $items = $request->getAllItems();
        if (!$items) {
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

    protected function getQuoteFromCurrentSession(): ?Quote
    {
        $session = ObjectManager::getInstance()->get(Session::class);
        $quote   = $session->getQuote();

        if (!($quote instanceof Quote)) {
            return null;
        }

        /**
         * The available shipping methods can be found in the quote from the session, so force re-checking
         * of the availability of free shipping now, by unsetting the session variable.
         */
        $session->unsMyParcelFreeShippingIsAvailable();

        return $quote;
    }

    protected function getDeliveryOptionsFromQuote(Quote $quote): AbstractDeliveryOptionsAdapter
    {
        if (isset($this->deliveryOptions)) {
            return $this->deliveryOptions;
        }

        $deliveryOptions = $quote->getData(Config::FIELD_DELIVERY_OPTIONS);

        if (is_string($deliveryOptions)) {
            try {
                $this->deliveryOptions = DeliveryOptionsAdapterFactory::create(json_decode($deliveryOptions, true, 512, JSON_THROW_ON_ERROR));
            } catch (\Throwable $e) {
                Logger::log('warning', "Failed to retrieve delivery options from quote {$quote->getId()}", (array) $deliveryOptions);
                $this->deliveryOptions = new DeliveryOptionsV3Adapter();
            }
        } else {
            $this->deliveryOptions = new DeliveryOptionsV3Adapter();
        }

        return $this->deliveryOptions;
    }

    /**
     * @param Quote $quote
     * @return array indexed array of ShippingMethodInterface objects
     * @throws LocalizedException
     */
    protected function getShippingMethodsFromQuote(Quote $quote): array
    {
        $quoteId = $quote->getId();

        try {
            $shippingMethodManagement = ObjectManager::getInstance()->get(ShippingMethodManagementInterface::class);
            $shippingMethods          = $shippingMethodManagement->getList($quoteId);

            $methods = [];
            foreach ($shippingMethods as $method) {
                /** @var ShippingMethodInterface $method */
                $methods[] = $method;
            }
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
        $session = ObjectManager::getInstance()->get(Session::class);

        // NULL when not set, boolean value when set
        $freeShippingIsAvailable = $session->getMyParcelFreeShippingIsAvailable();

        if (NULL !== $freeShippingIsAvailable) {
            return $freeShippingIsAvailable;
        }

        $freeShippingIsAvailable = false;

        try {
            $shippingMethods = $this->getShippingMethodsFromQuote($quote);

            foreach ($shippingMethods as $method) {
                /** @var ShippingMethodInterface $method */
                if ('freeshipping' === $method->getCarrierCode()) {
                    $freeShippingIsAvailable = true;
                    break;
                }
            }
        } catch (LocalizedException $e) {
            Logger::critical($e->getMessage());
        }

        $session->setMyParcelFreeShippingIsAvailable($freeShippingIsAvailable);

        return $freeShippingIsAvailable;
    }
}
