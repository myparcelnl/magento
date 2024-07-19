<?php

namespace MyParcelBE\Magento\Api;

/**
 * Get delivery options
 */
interface ShippingMethodsInterface
{
    /**
     * @param mixed $deliveryOptions
     *
     * @return mixed[] specifying array[] breaks the soap api
     * @api
     */
    public function getFromDeliveryOptions($deliveryOptions): array;
}
