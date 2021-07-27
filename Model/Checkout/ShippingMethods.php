<?php

namespace MyParcelNL\Magento\Model\Checkout;

use Exception;
use Magento\Checkout\Model\Session;
use MyParcelNL\Magento\Api\ShippingMethodsInterface;

/**
 * @since 3.0.0
 */
class ShippingMethods implements ShippingMethodsInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $session;

    /**
     * ShippingMethods constructor.
     *
     * @param \Magento\Checkout\Model\Session $session
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * @param array[] $deliveryOptions indexed array holding 1 deliveryOptions object
     *
     * @return array[]
     * @throws Exception
     */
    public function getFromDeliveryOptions(array $deliveryOptions): array
    {
        if (! $deliveryOptions) {
            return [];
        }

        $deliveryOptions = $deliveryOptions[0];

        try {
            $shipping = new DeliveryOptionsToShippingMethods($deliveryOptions);

            $response = [
                'root' => [
                    'element_id' => $shipping->getShippingMethod(),
                ],
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => '422',
                'message' => $e->getMessage(),
            ];
        }

        $response[] = $this->persistDeliveryOptions($deliveryOptions);

        return $response;
    }

    /**
     * @param array $deliveryOptions
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function persistDeliveryOptions(array $deliveryOptions): array
    {
        $quote = $this->session->getQuote();
        $quote->addData(['myparcel_delivery_options' => json_encode($deliveryOptions)]);
        $quote->save();

        return [
            'delivery_options' => $deliveryOptions,
            'message'          => 'Delivery options persisted in quote ' . $quote->getId(),
        ];
    }
}
