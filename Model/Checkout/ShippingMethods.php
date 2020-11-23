<?php

namespace MyParcelNL\Magento\Model\Checkout;

use Exception;
use MyParcelNL\Magento\Api\ShippingMethodsInterface;

/**
 * @since 3.0.0
 */
class ShippingMethods implements ShippingMethodsInterface
{
    /**
     * @param mixed $deliveryOptions
     *
     * @return mixed[]
     * @throws Exception
     */
    public function getFromDeliveryOptions($deliveryOptions)
    {
        if (! $deliveryOptions[0]) {
            return [];
        }

        try {
            $shipping = new DeliveryOptionsToShippingMethods($deliveryOptions[0]);

            $response = [
                "root" => [
                    "element_id" => $shipping->getShippingMethod(),
                ],
            ];
        } catch (Exception $e) {
            $response = [
                "code"    => "422",
                "message" => $e->getMessage(),
            ];
        }

        return $response;
    }
}
