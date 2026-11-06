<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Shipment option and extra option keys, and the facts the module owns about each option.
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

    /** The yes/no facts having() takes. A missing one is false. */
    private const FLAGS = ['toggle', 'checkout', 'fromPrice', 'fromPriceTooltip', 'mailbox', 'keepAtCheckout', 'restV1'];

    /** The text facts fact() takes. A missing one is null. */
    private const TEXTS = ['label', 'settingsLabel', 'tooltip', 'rateTitle', 'v2Name'];

    /**
     * What the module knows about an option that capabilities do not say, one row per option. Row
     * order is the order of the settings form. An option that capabilities offer needs no row.
     *
     * - toggle: the form shows it when capabilities could not be read, and it is a stored key.
     * - checkout: the checkout widget renders it, with a fee in the settings.
     * - fromPrice, fromPriceTooltip: the settings automate it above an order total.
     * - mailbox: its settings go in the mailbox group instead of default_options.
     * - keepAtCheckout: when the cart forces it, the checkout sends it to capabilities as a filter,
     *   so it offers nothing that cannot carry it. A default such as signature must not be one:
     *   the PostNL mailbox excludes it, so mailbox would disappear for every cart.
     * - restV1: REST v1 publishes it.
     * - label: English, also the msgid in i18n/. Without one, the name is the label.
     * - settingsLabel, tooltip: the settings form's own label and tooltip.
     * - rateTitle: shown in the shipping method title when the option is on.
     * - v2Name: the wire key, only when it does not derive from the name. V2NameMapTest pins each one
     *   to the SDK.
     */
    public const DEFINITIONS
        = [
            self::SIGNATURE          => [
                'toggle'           => true,
                'checkout'         => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'restV1'           => true,
                'label'            => 'Signature on receipt',
                'rateTitle'        => 'Signature',
                'v2Name'           => 'requiresSignature',
            ],
            self::RECEIPT_CODE       => [
                'toggle'    => true,
                'checkout'  => true,
                'fromPrice' => true,
                'restV1'    => true,
                'label'     => 'Receipt code',
                'rateTitle' => 'Receipt code',
                'v2Name'    => 'requiresReceiptCode',
            ],
            self::ONLY_RECIPIENT     => [
                'toggle'           => true,
                'checkout'         => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'restV1'           => true,
                'label'            => 'Only recipient',
                'rateTitle'        => 'Only recipient',
                'v2Name'           => 'recipientOnlyDelivery',
            ],
            self::RETURN             => [
                'toggle'           => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'restV1'           => true,
                'label'            => 'Return if no answer',
                'v2Name'           => 'returnOnFirstFailedDelivery',
            ],
            self::LARGE_FORMAT       => [
                'toggle'           => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'restV1'           => true,
                'label'            => 'Large package',
                'settingsLabel'    => 'Large format',
                'v2Name'           => 'oversizedPackage',
            ],
            self::COLLECT            => [
                'toggle'    => true,
                'fromPrice' => true,
                'restV1'    => true,
                'label'     => 'Collect package',
                'v2Name'    => 'scheduledCollection',
            ],
            self::AGE_CHECK          => [
                'toggle'         => true,
                'keepAtCheckout' => true,
                'restV1'         => true,
                'label'          => 'Age check 18+',
                'tooltip'        => 'The age check is intended for parcel shipments for which the recipient must show 18+ by means of a proof of identity. With this shipping option Signature on receipt and Only recipient are included. The age 18+ is further excluded from the delivery options morning and evening delivery.',
                'v2Name'         => 'requiresAgeVerification',
            ],
            self::HIDE_SENDER        => [
                'toggle'    => true,
                'restV1'    => true,
                'label'     => 'Hide sender',
                'tooltip'   => 'Activating the \'hide sender\' option will remove the name and address of the sender from the label',
                'rateTitle' => 'Hide sender',
            ],
            self::FRESH_FOOD         => [
                'toggle'    => true,
                'fromPrice' => true,
                'label'     => 'Fresh food',
            ],
            self::FROZEN             => [
                'toggle'    => true,
                'fromPrice' => true,
                'label'     => 'Frozen',
            ],
            self::PRIORITY_DELIVERY  => [
                'toggle'    => true,
                'mailbox'   => true,
                'restV1'    => true,
                'label'     => 'Priority delivery',
                'rateTitle' => 'Priority delivery',
            ],
            self::SAME_DAY_DELIVERY  => [
                'restV1'    => true,
                'label'     => 'Same day delivery',
                'rateTitle' => 'Same day delivery',
            ],
            self::PRINTERLESS_RETURN => [
                'label'  => 'Printerless return',
                'v2Name' => 'printReturnLabelAtDropOff',
            ],
        ];

    /**
     * The options whose row sets a yes/no fact, in row order.
     *
     * @return string[]
     */
    public static function having(string $flag): array
    {
        if (! in_array($flag, self::FLAGS, true)) {
            throw new \InvalidArgumentException("Unknown shipment option fact '$flag'");
        }

        return array_keys(array_filter(self::DEFINITIONS, static fn(array $row): bool => $row[$flag] ?? false));
    }

    /** A text fact of an option, or null when its row does not set it. */
    public static function fact(string $option, string $text): ?string
    {
        if (! in_array($text, self::TEXTS, true)) {
            throw new \InvalidArgumentException("Unknown shipment option fact '$text'");
        }

        return self::DEFINITIONS[$option][$text] ?? null;
    }

    /** A snake_case module name. Request input that is not one is dropped, never guessed at. */
    public static function isOptionName($value): bool
    {
        return is_string($value) && 1 === preg_match('/^[a-z][a-z0-9_]*$/', $value);
    }

    /** Module option name to the camelCase key a capabilities response uses. */
    public static function toV2Name(string $name): string
    {
        return self::fact($name, 'v2Name') ?? lcfirst(str_replace('_', '', ucwords($name, '_')));
    }

    public static function fromV2Name(string $v2Name): string
    {
        foreach (self::DEFINITIONS as $name => $row) {
            if (($row['v2Name'] ?? null) === $v2Name) {
                return $name;
            }
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $v2Name));
    }

    /** An option without a label reads as its name, so `no_tracking` is "No tracking". */
    public static function labelFor(string $option): string
    {
        return self::fact($option, 'label') ?? ucfirst(str_replace('_', ' ', $option));
    }
}
