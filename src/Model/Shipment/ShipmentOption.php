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
     * The checkboxes the New Shipment form shows when capabilities could not be read, and the fixed
     * front of the persisted key order. With capabilities, the form shows what they offer instead.
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
     * Options that rule out a package type when the merchant has forced them on.
     *
     * Stated, not derived from the config shape: a forced option only narrows the choice when it
     * applies to the whole order. Priority delivery is the counter-example — it is a mailbox
     * option, so treating it as limiting would rule out every package type but mailbox, backwards.
     *
     * Which types can carry one is the capabilities API's answer, per carrier: DPD may offer an
     * age check on a mailbox where PostNL does not.
     *
     * @var string[]
     */
    public const LIMIT_PACKAGE_TYPE = [self::AGE_CHECK];

    /**
     * Options the API accepts on some delivery types only.
     *
     * Not a capabilities fact: the capabilities response answers per carrier and package type, not
     * per delivery type, so this rule is the module's own. Stating it here keeps the export and the
     * admin form from disagreeing, which they did — one gated on country and carrier as well.
     *
     * The checkout cannot use this: it builds one config per carrier before the customer has picked
     * a delivery type, and the widget decides what to render under each.
     *
     * @var array<string,string[]>
     */
    public const LIMIT_DELIVERY_TYPE = [self::RECEIPT_CODE => [DeliveryType::STANDARD_NAME]];

    /**
     * Whether the option may be set alongside this delivery type. An unknown delivery type counts
     * as standard, so an order stored without one keeps working.
     */
    public static function allowedForDeliveryType(string $option, ?string $deliveryType): bool
    {
        $allowed = self::LIMIT_DELIVERY_TYPE[$option] ?? null;

        if (null === $allowed) {
            return true;
        }

        return in_array($deliveryType ?? DeliveryType::DEFAULT_NAME, $allowed, true);
    }

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
