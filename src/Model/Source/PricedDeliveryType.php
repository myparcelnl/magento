<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use MyParcelNL\Magento\Model\Shipment\DeliveryType;

/**
 * The delivery types that the delivery costs matrix prices per type.
 *
 * Other delivery types take the standard price, see DeliveryCosts::calculate().
 * TITLES is the label of each type in the carrier's method title and in the matrix UI.
 */
class PricedDeliveryType
{
    public const DEFAULT = DeliveryType::STANDARD_NAME;

    public const NAMES = [
        DeliveryType::STANDARD_NAME,
        DeliveryType::PICKUP_NAME,
        DeliveryType::MORNING_NAME,
        DeliveryType::EVENING_NAME,
    ];

    public const TITLES = [
        DeliveryType::MORNING_NAME  => 'Morning Delivery',
        DeliveryType::STANDARD_NAME => 'Standard Delivery',
        DeliveryType::EVENING_NAME  => 'Evening Delivery',
        DeliveryType::PICKUP_NAME   => 'Pickup locations',
    ];
}
