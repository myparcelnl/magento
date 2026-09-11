<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Translates between module names and the Core API v2 vocabulary.
 *
 * Requires the using class to declare: public const V2_NAMES_MAP, module name => v2 name.
 */
trait MapsV2Names
{
    public static function toV2Name(string $name): ?string
    {
        return self::V2_NAMES_MAP[$name] ?? null;
    }

    /** Null for a v2 name the module does not know; the caller logs it rather than inventing one. */
    public static function fromV2Name(string $v2Name): ?string
    {
        $name = array_search($v2Name, self::V2_NAMES_MAP, true);

        return false === $name ? null : $name;
    }
}
