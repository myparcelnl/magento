<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryType;
use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\RefTypesDeliveryTypeV2;

/**
 * Delivery type names and ids.
 *
 * Same split as PackageType: names ours, ids the SDK's, so always hand the SDK an id.
 *
 * SAME_DAY is the delivery type 'same_day'. ShipmentOption::SAME_DAY_DELIVERY is the string
 * 'same_day_delivery' and a different thing entirely.
 */
final class DeliveryType
{
    use MapsNamesToIds;
    use MapsV2Names;

    /** Names the type in an exception message. */
    public const TYPE_LABEL = 'delivery type';

    public const MORNING       = RefTypesDeliveryType::MORNING;
    public const STANDARD      = RefTypesDeliveryType::STANDARD;
    public const EVENING       = RefTypesDeliveryType::EVENING;
    public const PICKUP        = RefTypesDeliveryType::PICKUP;
    public const SAME_DAY      = RefTypesDeliveryType::SAME_DAY;
    public const EXPRESS       = RefTypesDeliveryType::EXPRESS;
    public const EARLY_MORNING = RefTypesDeliveryType::EARLY_MORNING;

    // Every name is the v2 enum minus its _DELIVERY suffix, lower-cased; DeliveryTypeTransformer
    // puts the suffix back, so a new type needs no entry there.
    public const MORNING_NAME       = 'morning';
    public const STANDARD_NAME      = 'standard';
    public const EVENING_NAME       = 'evening';
    public const PICKUP_NAME        = 'pickup';
    public const SAME_DAY_NAME      = 'same_day';
    public const EXPRESS_NAME       = 'express';
    public const EARLY_MORNING_NAME = 'early_morning';

    public const DEFAULT      = self::STANDARD;
    public const DEFAULT_NAME = self::STANDARD_NAME;

    public const NAMES_IDS_MAP
        = [
            self::MORNING_NAME       => self::MORNING,
            self::STANDARD_NAME      => self::STANDARD,
            self::EVENING_NAME       => self::EVENING,
            self::PICKUP_NAME        => self::PICKUP,
            self::SAME_DAY_NAME      => self::SAME_DAY,
            self::EXPRESS_NAME       => self::EXPRESS,
            self::EARLY_MORNING_NAME => self::EARLY_MORNING,
        ];

    /**
     * Module name to the Core API v2 name a capabilities response speaks.
     */
    public const V2_NAMES_MAP
        = [
            self::MORNING_NAME       => RefTypesDeliveryTypeV2::MORNING,
            self::STANDARD_NAME      => RefTypesDeliveryTypeV2::STANDARD,
            self::EVENING_NAME       => RefTypesDeliveryTypeV2::EVENING,
            self::PICKUP_NAME        => RefTypesDeliveryTypeV2::PICKUP,
            self::SAME_DAY_NAME      => RefTypesDeliveryTypeV2::SAME_DAY,
            self::EXPRESS_NAME       => RefTypesDeliveryTypeV2::EXPRESS,
            self::EARLY_MORNING_NAME => RefTypesDeliveryTypeV2::EARLY_MORNING,
        ];
}
