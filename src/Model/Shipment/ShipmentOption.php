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

    /**
     * Module option name to the camelCase key a capabilities response uses.
     *
     * Mirrors CapabilitiesMapper's own request-side mapping, so the two sides cannot disagree on a
     * wire key. ShipmentOptionV2KeyTest asserts that agreement by round-tripping every entry
     * through mapToCoreApi(); FRESH_FOOD and FROZEN are the two the request model cannot carry at
     * all, so they are read-only and the Client logs them when they are passed.
     */
    public const V2_KEYS_MAP
        = [
            self::AGE_CHECK          => 'requiresAgeVerification',
            self::HIDE_SENDER        => 'hideSender',
            self::INSURANCE          => 'insurance',
            self::LARGE_FORMAT       => 'oversizedPackage',
            self::ONLY_RECIPIENT     => 'recipientOnlyDelivery',
            self::PRINTERLESS_RETURN => 'printReturnLabelAtDropOff',
            self::RETURN             => 'returnOnFirstFailedDelivery',
            self::SAME_DAY_DELIVERY  => 'sameDayDelivery',
            self::SIGNATURE          => 'requiresSignature',
            self::COLLECT            => 'scheduledCollection',
            self::RECEIPT_CODE       => 'requiresReceiptCode',
            self::PRIORITY_DELIVERY  => 'priorityDelivery',
            self::FRESH_FOOD         => 'freshFood',
            self::FROZEN             => 'frozen',
        ];

    public static function toV2Key(string $name): ?string
    {
        return self::V2_KEYS_MAP[$name] ?? null;
    }

    /** Null for a response key the module has no option for; the caller logs it. */
    public static function fromV2Key(string $v2Key): ?string
    {
        $name = array_search($v2Key, self::V2_KEYS_MAP, true);

        return false === $name ? null : $name;
    }

    public const EXTRA_DELIVERY_DATE     = 'delivery_date';
    public const EXTRA_DELIVERY_MONDAY   = 'delivery_monday';
    public const EXTRA_DELIVERY_SATURDAY = 'delivery_saturday';
    public const EXTRA_MULTI_COLLO       = 'multi_collo';
}
