<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Observer;

use Magento\Checkout\Model\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Quote\Api\Data\ShippingMethodInterface;
use Magento\Quote\Api\ShippingMethodManagementInterface;
use Magento\Quote\Model\Quote;
use MyParcelNL\Magento\Facade\Logger;

class IsFreeShippingAvailable implements ObserverInterface
{
    private Session                           $session;
    private ShippingMethodManagementInterface $shippingMethodManagement;

    public function __construct(ShippingMethodManagementInterface $management, Session $session)
    {
        $this->shippingMethodManagement = $management;
        $this->session                  = $session;
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();

        if (! ($shippingAddress = $quote->getShippingAddress()) || ! $shippingAddress->getCountryId()) {
            // If the shipping address is not set, we cannot determine free shipping availability.
            return;
        }

        $freeShippingIsAvailable = false;

        $shippingMethods = $this->getShippingMethodsFromQuote($quote);

        foreach ($shippingMethods as $method) {
            /** @var ShippingMethodInterface $method */
            if ('freeshipping' === $method->getCarrierCode()) {
                $freeShippingIsAvailable = true;
                break;
            }
        }

        $this->session->setMyParcelFreeShippingIsAvailable($freeShippingIsAvailable);
    }

    /**
     * @param Quote $quote
     * @return array indexed array of ShippingMethodInterface objects
     */
    protected function getShippingMethodsFromQuote(Quote $quote): array
    {
        $quoteId = $quote->getId();
        $methods = [];

        try {
            $shippingMethods = $this->shippingMethodManagement->getList($quoteId);

            foreach ($shippingMethods as $method) {
                $methods[] = $method;
            }
        } catch (StateException $exception) {
            // We can safely ignore the shipping address missing exception here, because we will check again
            // when the shipping address is set.
        } catch (NoSuchEntityException $exception) {
            Logger::error('Quote does not exist.', ['quote_id' => $quoteId, 'exception' => $exception]);
        }

        return $methods;
    }
}
