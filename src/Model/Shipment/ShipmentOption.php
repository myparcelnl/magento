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
    use MapsV2Names;

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
     * Module option name to the camelCase key a capabilities response uses.
     *
     * Mirrors CapabilitiesMapper's own request-side mapping, so the two sides cannot disagree on a
     * wire key. V2NameMapTest asserts that agreement by round-tripping every entry through
     * mapToCoreApi(). An option the request model gains no setter for is dropped silently, which
     * is why Client logs what it dropped.
     */
    public const V2_NAMES_MAP
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
}
