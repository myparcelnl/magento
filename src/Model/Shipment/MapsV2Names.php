<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Translates between module names and the Core API v2 vocabulary.
 *
 * Requires the using class to declare: public const V2_NAMES_MAP, module name => v2 name.
 *
 * @todo INT-1563 replaces this hardcoded mapping with the generic SDK helpers.
 */
trait MapsV2Names
{
    /** Get the v2 name for a module name, or null when the module does not map it. */
    public static function toV2Name(string $name): ?string
    {
        return self::V2_NAMES_MAP[$name] ?? null;
    }

    /** Get the module name for a v2 name, or null when the module does not know it; the caller leaves it out. */
    public static function fromV2Name(string $v2Name): ?string
    {
        $name = array_search($v2Name, self::V2_NAMES_MAP, true);

        return false === $name ? null : $name;
    }
}
