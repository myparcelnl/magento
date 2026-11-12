<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Source;

use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

/**
 * The subset of AbstractConsignment::DELIVERY_TYPES_NAMES that the delivery costs matrix prices per type.
 *
 * Other delivery types take the standard price, see DeliveryCosts::calculate().
 * TITLES is the label of each type in the carrier's method title and in the matrix UI.
 */
class PricedDeliveryType
{
    public const DEFAULT = AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME;

    public const NAMES = [
        AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME,
        AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME,
        AbstractConsignment::DELIVERY_TYPE_MORNING_NAME,
        AbstractConsignment::DELIVERY_TYPE_EVENING_NAME,
    ];

    public const TITLES = [
        AbstractConsignment::DELIVERY_TYPE_MORNING_NAME  => 'Morning Delivery',
        AbstractConsignment::DELIVERY_TYPE_STANDARD_NAME => 'Standard Delivery',
        AbstractConsignment::DELIVERY_TYPE_EVENING_NAME  => 'Evening Delivery',
        AbstractConsignment::DELIVERY_TYPE_PICKUP_NAME   => 'Pickup locations',
    ];
}
