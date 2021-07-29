<?php

namespace MyParcelNL\Magento\Api;

/**
 * Get delivery options
 */
interface ShippingMethodsInterface
{
    /**
     * @param mixed $deliveryOptions
     *
     * @return array[]
     * @api
     */
    public function getFromDeliveryOptions($deliveryOptions): array;
}
