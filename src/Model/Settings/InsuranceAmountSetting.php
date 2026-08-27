<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Service\Config;

/**
 * Recognises an insurance amount setting by its config path, and says which carrier it configures.
 *
 * The zone in a field's name (`local`, `belgium`, `eu`, `row`) is a merchant's own cap per destination
 * zone and carries no bound of its own — contract definitions have no country (DR-19) — so the carrier
 * is the whole answer.
 */
final class InsuranceAmountSetting
{
    private const FIELDS = [
        'insurance_local_amount',
        'insurance_belgium_amount',
        'insurance_eu_amount',
        'insurance_row_amount',
    ];

    /**
     * The carrier whose insurance this path configures, or null when the path configures something
     * else entirely.
     */
    public static function carrierFor(string $path): ?string
    {
        $segments = explode('/', $path);

        if (! in_array(end($segments), self::FIELDS, true)) {
            return null;
        }

        // The path segment is not the carrier name — `ups` in a path is `upsstandard` — so the carrier
        // comes from reversing the path map rather than from parsing the string.
        foreach (Config::CARRIERS_XML_PATH_MAP as $carrierName => $pathPrefix) {
            if (0 === strpos($path, $pathPrefix)) {
                return $carrierName;
            }
        }

        return null;
    }
}
