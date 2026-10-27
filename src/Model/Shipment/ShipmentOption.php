<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Shipment option and extra option keys.
 *
 * These strings are config path segments and are stored on orders, so renaming one to match the
 * API's own vocabulary would break existing installs. See docs/sdk-v11.md for the API mapping.
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
     * The yes/no options the module knows by name. The form shows them when capabilities could not be
     * read, and they are the fixed front of the persisted key order. An option only capabilities name
     * is a discovered option.
     *
     * @var string[]
     */
    public const KNOWN_TOGGLES
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
     * Forced options that rule out a package type which cannot carry them.
     *
     * Only the age check: the export drops any other option a package type cannot carry, but an 18+
     * order must keep its age check. Priority delivery is not one: it is a mailbox option, and would
     * rule out every other package type. Which package type can carry it is the capabilities' answer.
     *
     * Keep this until the checkout sends the forced options to capabilities as a filter, so that
     * capabilities rule out the package type themselves.
     *
     * @var string[]
     */
    public const LIMIT_PACKAGE_TYPE = [self::AGE_CHECK];

    /**
     * Stored option names that do not follow from their camelCase wire key.
     *
     * Every other name derives both ways, so a new option needs no row here. These stay because
     * core_config_data and the order's delivery options already store them. V2NameMapTest pins each
     * wire key to the SDK's CapabilitiesMapper.
     */
    public const V2_NAMES_MAP
        = [
            self::AGE_CHECK          => 'requiresAgeVerification',
            self::LARGE_FORMAT       => 'oversizedPackage',
            self::ONLY_RECIPIENT     => 'recipientOnlyDelivery',
            self::PRINTERLESS_RETURN => 'printReturnLabelAtDropOff',
            self::RETURN             => 'returnOnFirstFailedDelivery',
            self::SIGNATURE          => 'requiresSignature',
            self::COLLECT            => 'scheduledCollection',
            self::RECEIPT_CODE       => 'requiresReceiptCode',
        ];

    /** A snake_case module name. Request input that is not one is dropped, never guessed at. */
    public static function isOptionName($value): bool
    {
        return is_string($value) && 1 === preg_match('/^[a-z][a-z0-9_]*$/', $value);
    }

    /** Module option name to the camelCase key a capabilities response uses. */
    public static function toV2Name(string $name): string
    {
        return self::V2_NAMES_MAP[$name] ?? lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    public static function fromV2Name(string $v2Name): string
    {
        $name = array_search($v2Name, self::V2_NAMES_MAP, true);

        return false === $name ? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $v2Name)) : $name;
    }

    /** English labels, which are also the msgids in i18n/. Translate them where they are shown. */
    public const LABELS
        = [
            self::SIGNATURE          => 'Signature on receipt',
            self::RECEIPT_CODE       => 'Receipt code',
            self::COLLECT            => 'Collect package',
            self::ONLY_RECIPIENT     => 'Only recipient',
            self::AGE_CHECK          => 'Age check 18+',
            self::HIDE_SENDER        => 'Hide sender',
            self::LARGE_FORMAT       => 'Large package',
            self::RETURN             => 'Return if no answer',
            self::SAME_DAY_DELIVERY  => 'Same day delivery',
            self::PRINTERLESS_RETURN => 'Printerless return',
            self::FRESH_FOOD         => 'Fresh food',
            self::FROZEN             => 'Frozen',
            self::PRIORITY_DELIVERY  => 'Priority delivery',
        ];

    /** An option not in LABELS reads as its name, so `no_tracking` is "No tracking". */
    public static function labelFor(string $option): string
    {
        return self::LABELS[$option] ?? ucfirst(str_replace('_', ' ', $option));
    }
}
