<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use InvalidArgumentException;

/**
 * Translates between module names and the SDK ids.
 *
 * Requires the using class to declare: public const NAMES_IDS_MAP, module name => SDK id, and
 * public const TYPE_LABEL, lower case, which names the type in an exception message.
 */
trait MapsNamesToIds
{
    /**
     * Strict: use on any path that ends in an API request.
     *
     * @throws \InvalidArgumentException
     */
    public static function toId(string $name): int
    {
        if (! isset(self::NAMES_IDS_MAP[$name])) {
            throw new InvalidArgumentException(sprintf("Unknown %s '%s'", self::TYPE_LABEL, $name));
        }

        return self::NAMES_IDS_MAP[$name];
    }

    /** Forgiving: use on read paths. */
    public static function toIdOrNull(?string $name): ?int
    {
        return null === $name ? null : (self::NAMES_IDS_MAP[$name] ?? null);
    }

    /** @throws \InvalidArgumentException */
    public static function nameFromId(int $id): string
    {
        $name = self::nameFromIdOrNull($id);

        if (null === $name) {
            throw new InvalidArgumentException(sprintf("Unknown %s id '%d'", self::TYPE_LABEL, $id));
        }

        return $name;
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
