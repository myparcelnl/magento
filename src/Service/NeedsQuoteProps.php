<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Observer\IsFreeShippingAvailable;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\Factory\DeliveryOptionsAdapterFactory;

/**
 * Use this trait when you need to get the quote in several scenarios and have easy access to its properties.
 * The isFreeShippingAvailable session variable is set using an observer: @see IsFreeShippingAvailable
 *
 * @property AbstractDeliveryOptionsAdapter $deliveryOptions
 */
trait NeedsQuoteProps
{
    protected AbstractDeliveryOptionsAdapter $deliveryOptions;

    /**
     * Use this method during raterequest, because getting it from session will cause infinite loop for
     * quotes with trigger_recollect = 1, see Quote::_afterLoad()
     * https://magento.stackexchange.com/questions/340048/how-to-properly-get-current-quote-in-carrier-collect-rates-function
     */
    protected function getQuoteFromRateRequest(RateRequest $request): ?Quote
    {
        $items = $request->getAllItems();
        if (! $items) {
            return null;
        }

        /** @var \Magento\Quote\Model\Quote\Item $firstItem */
        $firstItem = reset($items);
        if (! $firstItem) {
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
     * Returns the session variable that indicates whether free shipping is available, since often the quote is not
     * usable to determine this, we use an observer that receives the quote and sets this session variable.
     * @see IsFreeShippingAvailable.
     * @return bool
     */
    public function isFreeShippingAvailable(): bool
    {
        return ObjectManager::getInstance()->get(Session::class)->getMyParcelFreeShippingIsAvailable();
    }
}
