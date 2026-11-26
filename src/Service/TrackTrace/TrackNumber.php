<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\TrackTrace;

/**
 * The values a MyParcel track number can hold: a real barcode, or a placeholder that stands in for one.
 *
 * The status cron selects the tracks that still hold a placeholder, so a new placeholder goes in
 * PLACEHOLDERS or that cron never updates its track.
 */
class TrackNumber
{
    public const EMPTY   = '–';
    public const PRINTED = 'printed';

    /** @var string[] */
    public const PLACEHOLDERS = [self::EMPTY, self::PRINTED];

    /** The first character of a barcode list on sales_order.track_number. */
    public const ORDER_ROW_LIST_PREFIX = '[';

    /** Check whether the number stands in for a barcode instead of being one. */
    public static function isPlaceholder(?string $number): bool
    {
        return in_array((string) $number, self::PLACEHOLDERS, true);
    }

    /** Check whether the number identifies a real parcel: present, and not a placeholder. */
    public static function isRealBarcode(?string $number): bool
    {
        return '' !== (string) $number && ! self::isPlaceholder($number);
    }

    /**
     * Get the barcodes that the status cron stored on the order row when it shipped an order without
     * a shipment. Null for a value that is not such a list.
     *
     * @return string[]|null
     */
    public static function barcodesFromOrderRow(?string $value): ?array
    {
        $value    = (string) $value;
        $barcodes = self::ORDER_ROW_LIST_PREFIX === substr($value, 0, 1) ? json_decode($value, true) : null;

        if (! is_array($barcodes)) {
            return null;
        }

        return array_values(array_filter(
            array_map('strval', $barcodes),
            static fn(string $barcode): bool => self::isRealBarcode($barcode)
        ));
    }

    /**
     * Get the value the status cron stores on the order row for these barcodes.
     *
     * @param string[] $barcodes
     */
    public static function toOrderRow(array $barcodes): string
    {
        return json_encode(array_values($barcodes), JSON_THROW_ON_ERROR);
    }
}
