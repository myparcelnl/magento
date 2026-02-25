<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

class DeliveryTypeTransformer
{
    private const MAP = [
        'standard' => 'STANDARD_DELIVERY',
        'morning'  => 'MORNING_DELIVERY',
        'evening'  => 'EVENING_DELIVERY',
        'pickup'   => 'PICKUP_DELIVERY',
        'express'  => 'EXPRESS_DELIVERY',
    ];

    public function transform(?string $deliveryType): ?string
    {
        if ($deliveryType === null) {
            return null;
        }

        return self::MAP[$deliveryType] ?? strtoupper($deliveryType) . '_DELIVERY';
    }
}
