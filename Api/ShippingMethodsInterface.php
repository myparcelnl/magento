<?php

namespace MyParcelNL\Magento\Api;

/**
 * Get delivery options
 */
interface ShippingMethodsInterface
{
    /**
     * @param array $deliveryOptions
     *
     * @return array[]
     * @api
     */
    public function getFromDeliveryOptions(array $deliveryOptions): array;
}
