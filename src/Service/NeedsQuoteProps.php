<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\Data\EstimateAddressInterface;
use Magento\Quote\Api\Data\EstimateAddressInterfaceFactory;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface as ShippingMethodManagementApi;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\RateRequest;
use MyParcelNL\Magento\Facade\Logger;
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
     * Returns the session variable that indicates whether free shipping is available. This variable is set when
     * the config is retrieved during checkout, because that is the only time we have the correct address + the quote.
     * @see MyParcelNL\Magento\Model\Quote\Checkout
     * @return bool|null null should be considered false
     */
    public function isFreeShippingAvailable(): ?bool
    {
        return ObjectManager::getInstance()->get(Session::class)->getMyParcelFreeShippingIsAvailable();
    }

    public function setFreeShippingAvailability(Quote $quote, array $forAddress): void
    {
        if (!isset($forAddress['countryId'])) {
            return;
        }

        $freeShippingIsAvailable = false;
        /* var EstimateAddress $address */
        $address = ObjectManager::getInstance()->get(EstimateAddressInterfaceFactory::class)->create();
        $address->setCountryId($forAddress['countryId']);
        $address->setRegion($forAddress['region'] ?? '');
        $address->setPostcode($forAddress['postcode'] ?? '');
        $methods = $this->estimateShippingMethods($quote, $address);
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', var_export($this->country, true) . " <- country in NeedsQuoteProps\n", FILE_APPEND);
        file_put_contents('/Applications/MAMP/htdocs/magento246/var/log/joeri.log', var_export(count($methods), true) . " methods\n", FILE_APPEND);

        foreach ($methods as $method) {
            /** @var ShippingMethodInterface $method */
            if ('freeshipping' === $method->getCarrierCode()) {
                $freeShippingIsAvailable = true;
                break;
            }
        }

        ObjectManager::getInstance()->get(Session::class)->setMyParcelFreeShippingIsAvailable($freeShippingIsAvailable);
    }

    /**
     * @param Quote                    $quote
     * @param EstimateAddressInterface $address
     * @return array indexed array of ShippingMethodInterface objects
     */
    protected function estimateShippingMethods(Quote $quote, EstimateAddressInterface $address): array
    {
        $quoteId = $quote->getId();
        $methods = [];
        $manager = ObjectManager::getInstance()->get(ShippingMethodManagementApi::class);

        try {
            $shippingMethods = $manager->estimateByAddress($quoteId, $address);

            foreach ($shippingMethods as $method) {
                $methods[] = $method;
            }
        } catch (StateException $exception) {
            Logger::error('Shipping address is missing.', ['quote_id' => $quoteId, 'exception' => $exception]);
        } catch (NoSuchEntityException $exception) {
            Logger::error('Quote does not exist.', ['quote_id' => $quoteId, 'exception' => $exception]);
        }

        return $methods;
    }
}
