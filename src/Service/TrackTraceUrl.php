<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

/**
 * Builds a consumer track & trace URL, replacing the SDK helper deleted at beta.22.
 *
 * The base URL is provisional: beta.31 returns the real link per shipment. See Phase 7.
 */
final class TrackTraceUrl
{
    public const CONSUMER_PORTAL_BASE_URL = 'https://myparcel.me/track-trace/';

    public static function create(string $barcode, string $postalCode, ?string $countryCode = null): string
    {
        $postalCode = str_replace(' ', '', $postalCode);

        $url = self::CONSUMER_PORTAL_BASE_URL . "$barcode/$postalCode";

        if ($countryCode) {
            $url .= "/$countryCode";
        }

        return $url;
    }
}
