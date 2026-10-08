<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Cleans a list of entity ids coming off a request, a grid selection or a track row.
 *
 * A zero is dropped: it identifies no row, and passing one to a batch API call asks it about a
 * shipment nobody owns. Values go through intval(), so `7abc` becomes 7; the ids are values
 * Magento rendered, so a malformed one does not arrive.
 */
final class IdList
{
    /**
     * @param array<mixed> $ids
     *
     * @return int[] non-zero ids, each once, renumbered from zero
     */
    public static function ints(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * A request parameter: an array of ids, or one comma-separated string.
     *
     * @param mixed $value
     *
     * @return int[]
     */
    public static function fromParam($value): array
    {
        return self::ints(is_string($value) ? explode(',', $value) : (array) $value);
    }
}
