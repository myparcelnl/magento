<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Shipment option and extra option keys.
 *
 * These strings are config path segments and are stored on orders, so renaming one to match the
 * API's own vocabulary would break existing installs. See TR-000005 for the API mapping.
 */
final class ShipmentOption
{
    public const AGE_CHECK          = 'age_check';
    public const HIDE_SENDER        = 'hide_sender';
    public const INSURANCE          = 'insurance';
    public const LARGE_FORMAT       = 'large_format';
    public const ONLY_RECIPIENT     = 'only_recipient';
    public const PRINTERLESS_RETURN = 'printerless_return';
    public const RETURN             = 'return';
    public const SAME_DAY_DELIVERY  = 'same_day_delivery';
    public const SIGNATURE          = 'signature';
    public const COLLECT            = 'collect';
    public const RECEIPT_CODE       = 'receipt_code';
    public const PRIORITY_DELIVERY  = 'priority_delivery';
    public const FRESH_FOOD         = 'fresh_food';
    public const FROZEN             = 'frozen';

    /**
     * The subset the admin New Shipment form asks the carrier about.
     *
     * @var string[]
     */
    public const TO_CHECK
        = [
            self::AGE_CHECK,
            self::HIDE_SENDER,
            self::LARGE_FORMAT,
            self::ONLY_RECIPIENT,
            self::RETURN,
            self::SIGNATURE,
            self::COLLECT,
            self::RECEIPT_CODE,
            self::PRIORITY_DELIVERY,
            self::FRESH_FOOD,
            self::FROZEN,
        ];

    public const EXTRA_DELIVERY_DATE     = 'delivery_date';
    public const EXTRA_DELIVERY_MONDAY   = 'delivery_monday';
    public const EXTRA_DELIVERY_SATURDAY = 'delivery_saturday';
    public const EXTRA_MULTI_COLLO       = 'multi_collo';
}
