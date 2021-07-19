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
    private $session;

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * @param mixed $deliveryOptions
     *
     * @return mixed[]
     * @throws Exception
     */
    public function getFromDeliveryOptions($deliveryOptions): array
    {
        if (! $deliveryOptions[0]) {
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

        $quote = $this->session->getQuote();
        $quote->addData(['myparcel_delivery_options'=> json_encode($deliveryOptions)]);
        $quote->save();
        $response[] = [
            'delivery_options'=>$deliveryOptions,
            'message'=>'shipping method persisted in quote ' . $quote->getId()
        ];

        return $response;
    }
}
