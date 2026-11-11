<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Client\Generated\CoreApi\Model\CapabilitiesOptionsV2;
use MyParcelNL\Sdk\Services\Mapping\ShipmentOptionMapper;

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

    /**
     * The yes/no facts having() takes; a missing one is false. toggle: shown when capabilities could
     * not be read, and a stored key. checkout: in the widget, with a fee. fromPrice(Tooltip): automated
     * above an order total. mailbox: set in the mailbox group.
     */
    private const FLAGS = ['toggle', 'checkout', 'fromPrice', 'fromPriceTooltip', 'mailbox'];

    /** The text facts fact() takes; a missing one is null. label is English and the i18n msgid. */
    private const TEXTS = ['label', 'settingsLabel', 'tooltip'];

    /** @var array<string,string[]> having() per flag */
    private static $having = [];

    /** @var array<string,string> toV2Name() per name */
    private static $v2Names = [];

    /**
     * What the module knows about an option that capabilities do not say, one row per option, in
     * settings form order. An option that capabilities offer needs no row.
     */
    public const DEFINITIONS
        = [
            self::SIGNATURE          => [
                'toggle'           => true,
                'checkout'         => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'label'            => 'Signature on receipt',
            ],
            self::RECEIPT_CODE       => [
                'toggle'    => true,
                'checkout'  => true,
                'fromPrice' => true,
                'label'     => 'Receipt code',
            ],
            self::ONLY_RECIPIENT     => [
                'toggle'           => true,
                'checkout'         => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'label'            => 'Only recipient',
            ],
            self::RETURN             => [
                'toggle'           => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'label'            => 'Return if no answer',
            ],
            self::LARGE_FORMAT       => [
                'toggle'           => true,
                'fromPrice'        => true,
                'fromPriceTooltip' => true,
                'label'            => 'Large package',
                'settingsLabel'    => 'Large format',
            ],
            self::COLLECT            => [
                'toggle'    => true,
                'fromPrice' => true,
                'label'     => 'Collect package',
            ],
            self::AGE_CHECK          => [
                'toggle'  => true,
                'label'   => 'Age check 18+',
                'tooltip' => 'The age check is intended for parcel shipments for which the recipient must show 18+ by means of a proof of identity. With this shipping option Signature on receipt and Only recipient are included. The age 18+ is further excluded from the delivery options morning and evening delivery.',
            ],
            self::HIDE_SENDER        => [
                'toggle'  => true,
                'label'   => 'Hide sender',
                'tooltip' => 'Activating the \'hide sender\' option will remove the name and address of the sender from the label',
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
                'toggle'  => true,
                'mailbox' => true,
                'label'   => 'Priority delivery',
            ],
            self::SAME_DAY_DELIVERY  => [
                'label' => 'Same day delivery',
            ],
            self::PRINTERLESS_RETURN => [
                'label'  => 'Printerless return',
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

        return self::$having[$flag] ??= array_keys(
            array_filter(self::DEFINITIONS, static fn(array $row): bool => $row[$flag] ?? false)
        );
    }

    /**
     * Put options in row order; an option without a row keeps its place after them.
     *
     * @param  string[] $options
     * @return string[]
     */
    public static function ordered(array $options): array
    {
        $known = array_values(array_intersect(array_keys(self::DEFINITIONS), $options));

        return array_merge($known, array_values(array_diff($options, $known)));
    }

    /** A text fact of an option, or null when its row does not set it. */
    public static function fact(string $option, string $text): ?string
    {
        if (! in_array($text, self::TEXTS, true)) {
            throw new \InvalidArgumentException("Unknown shipment option fact '$text'");
        }

        return self::DEFINITIONS[$option][$text] ?? null;
    }

    /** An option without a label reads as its name, so `no_tracking` is "No tracking". */
    public static function labelFor(string $option): string
    {
        return self::fact($option, 'label') ?? ucfirst(str_replace('_', ' ', $option));
    }

    /**
     * Options that rule out a package type when the merchant has forced them on.
     *
     * Stated, not derived from the config shape: a forced option only narrows the choice when it
     * applies to the whole order. Priority delivery is the counter-example: it is a mailbox
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
     * admin form from disagreeing, which they did, one gated on country and carrier as well.
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

    /** A snake_case module name. Request input that is not one is dropped, never guessed at. */
    public static function isOptionName($value): bool
    {
        return is_string($value) && 1 === preg_match('/^[a-z][a-z0-9_]*$/', $value);
    }

    /** Module option name to the camelCase key a capabilities response uses. The SDK names the aliases. */
    public static function toV2Name(string $name): string
    {
        if (! isset(self::$v2Names[$name])) {
            $property = (new ShipmentOptionMapper())->v2PropertyFromName($name);

            self::$v2Names[$name] = null === $property
                ? lcfirst(str_replace('_', '', ucwords($name, '_')))
                : CapabilitiesOptionsV2::attributeMap()[$property];
        }

        return self::$v2Names[$name];
    }

    /** A wire key back to the module name: an option with a row by its alias, any other by snake_case. */
    public static function fromV2Name(string $v2Name): string
    {
        foreach (array_keys(self::DEFINITIONS) as $name) {
            if (self::toV2Name($name) === $v2Name) {
                return $name;
            }
        }

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $v2Name));
    }
}
