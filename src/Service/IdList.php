<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Cleans a list of entity ids coming off a request, a grid selection or a track row.
 *
 * The four call sites that each spelled this out had drifted apart on whether a zero and a
 * non-numeric value survive. They do not: an id of zero identifies no row, and passing one to a
 * batch API call asks it about a shipment nobody owns.
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
}
