<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Translates between module names and the SDK ids.
 *
 * Requires the using class to declare public const NAMES_IDS_MAP, module name => SDK id. Both
 * lookups answer null for a value they do not know.
 *
 * @todo INT-1563 replaces this hardcoded mapping with the generic SDK helpers.
 */
trait MapsNamesToIds
{
    public static function toIdOrNull(?string $name): ?int
    {
        return null === $name ? null : (self::NAMES_IDS_MAP[$name] ?? null);
    }

    public static function nameFromIdOrNull(?int $id): ?string
    {
        if (null === $id) {
            return null;
        }

        $name = array_search($id, self::NAMES_IDS_MAP, true);

        return false === $name ? null : $name;
    }
}
