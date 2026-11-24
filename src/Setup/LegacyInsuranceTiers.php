<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup;

use MyParcelNL\Magento\Model\Shipment\Carrier;

/**
 * The insurance tier lists as SDK v11.0.0-beta.15 held them, frozen, in whole euros.
 *
 * Never validate a merchant's input against them: insurance is a range now. Only `Setup\UpgradeData`
 * uses them, to round pre-4.16 amounts up to a tier offline, without the API.
 */
class LegacyInsuranceTiers
{
    public const ZONE_LOCAL = 'local';
    public const ZONE_BE    = 'BE';
    public const ZONE_EU    = 'EU';

    private const FROM_100  = [100, 250, 500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000];
    private const FROM_500  = [500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000];
    private const FIFTY_500 = [50, 500];

    /** A zone absent from a carrier's entry gets an empty list, and snap() leaves the amount untouched. */
    private const TIERS = [
        Carrier::POSTNL             => [
            self::ZONE_LOCAL => self::FROM_100,
            self::ZONE_BE    => self::FROM_100,
            self::ZONE_EU    => self::FIFTY_500,
        ],
        Carrier::DHL_FOR_YOU       => [
            self::ZONE_LOCAL => self::FROM_500,
            self::ZONE_BE    => self::FROM_500,
        ],
        Carrier::DHL_EUROPLUS      => [
            self::ZONE_LOCAL => self::FROM_500,
            self::ZONE_BE    => self::FROM_500,
            self::ZONE_EU    => self::FROM_500,
        ],
        Carrier::DHL_PARCEL_CONNECT => [
            self::ZONE_EU => self::FROM_500,
        ],
    ];

    /**
     * The stored custom amount at this path, as an int, or 0 when none is stored.
     *
     * @param array<int, array{path: string, value: string|null}> $rows core_config_data rows
     */
    public static function customAmount(array $rows, string $path): int
    {
        foreach ($rows as $row) {
            if ($row['path'] === $path) {
                return (int) $row['value'];
            }
        }

        return 0;
    }

    /** @return int[] ascending; empty when the carrier offered no insurance in that zone */
    public static function forCarrierAndZone(string $carrier, string $zone): array
    {
        return self::TIERS[$carrier][$zone] ?? [];
    }

    /**
     * Rounds up to the nearest tier, or down to the largest when the amount exceeds it. Zero stays
     * zero, and an empty list returns the amount untouched.
     *
     * @param int[] $tiers
     */
    public static function snap(array $tiers, int $amount): int
    {
        if (0 === $amount || ! $tiers) {
            return $amount;
        }

        sort($tiers);

        foreach ($tiers as $tier) {
            if ($amount <= $tier) {
                return $tier;
            }
        }

        return end($tiers);
    }
}
