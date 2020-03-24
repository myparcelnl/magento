<?php

namespace MyParcelBE\Magento\Model\Checkout;

use Exception;
use MyParcelBE\Magento\Api\ShippingMethodsInterface;

/**
 * @since 3.0.0
 */
class ShippingMethods implements ShippingMethodsInterface
{
    /**
     * @param mixed $deliveryOptions
     *
     * @return array
     * @throws Exception
     */
    public function getFromDeliveryOptions($deliveryOptions): array
    {
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
