<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

use MyParcelNL\Magento\Service\IdList;

/**
 * Reads the return shipment ids a track holds, written when a return mail is sent.
 *
 * The column is a JSON list. A missing, empty or malformed value reads as no returns.
 */
final class ReturnIds
{
    public const FIELD = 'myparcel_return_ids';

    /**
     * @param \Magento\Framework\DataObject $track untyped: the unit tests' Track doubles are not DataObjects
     *
     * @return int[]
     */
    public static function of($track): array
    {
        $decoded = json_decode((string) $track->getData(self::FIELD), true);

        return is_array($decoded) ? IdList::ints($decoded) : [];
    }
}
