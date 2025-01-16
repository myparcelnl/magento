<?php

namespace MyParcelNL\Magento\Model\Checkout;

use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\QuoteRepository\SaveHandler;
use MyParcelNL\Magento\Api\ShippingMethodsInterface;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;

/**
 * @since 3.0.0
 */
class ShippingMethods implements ShippingMethodsInterface
{
    private Session $session;
    private Carrier $carrier;

    /**
     * ShippingMethods constructor.
     *
     * @param Session $session
     * @param Carrier $carrier
     */
    public function __construct(Session $session, Carrier $carrier)
    {
        $this->session = $session;
        $this->carrier = $carrier;
    }

    /**
     * Defined in etc/webapi.xml
     *
     * @param mixed $deliveryOptions indexed array holding 1 deliveryOptions object
     *
     * @return array[]
     * @throws Exception
     */
    public function getFromDeliveryOptions($deliveryOptions): array
    {
        if (! isset($deliveryOptions[0]) || ! $deliveryOptions[0]) {
            return [];
        }

        // save the delivery options in the quote
        $adapted = DeliveryOptionsAdapterFactory::create($deliveryOptions[0]);
        $quote = $this->session->getQuote();
        $quote->addData([Config::FIELD_DELIVERY_OPTIONS => json_encode($adapted->toArray(), JSON_THROW_ON_ERROR)]);
        $saver = ObjectManager::getInstance()->get(SaveHandler::class);
        $saver->save($quote);

        // return a fresh method from the carrier
        return ['root' => $this->carrier->getMethodForFrontend($quote)];
    }
}
