<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Api;

interface OrderDeliveryOptionsInterface
{
    /**
     * Get delivery options for an order in Order Service API format.
     *
     * @param int $orderId
     * @return string JSON response
     * @api
     */
    public function getByOrderId(int $orderId): string;
}
