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

    /** Whether the number stands in for a barcode instead of being one. */
    public static function isPlaceholder(?string $number): bool
    {
        return in_array((string) $number, self::PLACEHOLDERS, true);
    }

    /** Whether the number identifies a real parcel: present, and not a placeholder. */
    public static function isRealBarcode(?string $number): bool
    {
        return '' !== (string) $number && ! self::isPlaceholder($number);
    }

    /**
     * The barcodes of an order the status cron shipped without a shipment: it stores them as a JSON
     * list in sales_order.track_number. Null for any other value.
     *
     * @return string[]|null
     */
    public static function barcodesFromOrderRow(?string $value): ?array
    {
        $value    = (string) $value;
        $barcodes = '[' === substr($value, 0, 1) ? json_decode($value, true) : null;

        if (! is_array($barcodes)) {
            return null;
        }

        return array_values(array_filter(
            array_map('strval', $barcodes),
            static fn(string $barcode): bool => self::isRealBarcode($barcode)
        ));
    }
}
