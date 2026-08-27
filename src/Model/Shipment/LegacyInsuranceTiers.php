<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * The insurance tier lists as SDK v11.0.0-beta.15 held them, frozen.
 *
 * Insurance is a range now (FR-000009), so these are not a source of truth about what an account may
 * insure — never validate a merchant's input against them. They survive for two reasons only:
 *
 * 1. `Setup\UpgradeData` converts pre-4.16 configuration by rounding a stored amount up to a tier.
 *    It must reproduce the rows it produced when those tiers were live, and it must run offline
 *    (TR-000007), so it cannot ask the API.
 * 2. The DR-20 shim: on the pinned SDK, `AbstractConsignment::setInsurance()` throws for a domestic
 *    amount that is not a tier. Deleted with the shim in Phase 6.
 *
 * Values are whole euros, taken from the beta.15 consignment classes by running
 * getInsurancePossibilities() for each zone. `LegacyInsuranceTiersTest` asserts they still match
 * while the old SDK is installed.
 */
final class LegacyInsuranceTiers
{
    public const ZONE_LOCAL = 'local';
    public const ZONE_BE    = 'BE';
    public const ZONE_EU    = 'EU';
    public const ZONE_ROW   = 'ROW';

    private const FROM_100  = [100, 250, 500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000];
    private const FROM_500  = [500, 1000, 1500, 2000, 2500, 3000, 3500, 4000, 4500, 5000];
    private const FIFTY_500 = [50, 500];
    private const TEN_K     = [10000];

    /**
     * Zones absent from a carrier's entry had an empty list, which is not the same as "no rule": an
     * empty list made beta.15 reject every non-zero amount.
     */
    private const TIERS = [
        Carrier::POSTNL             => [
            self::ZONE_LOCAL => self::FROM_100,
            self::ZONE_BE    => self::FROM_100,
            self::ZONE_EU    => self::FIFTY_500,
            self::ZONE_ROW   => self::FIFTY_500,
        ],
        Carrier::DHL_FOR_YOU       => [
            self::ZONE_LOCAL => self::FROM_500,
            self::ZONE_BE    => self::FROM_500,
            self::ZONE_ROW   => self::FROM_100,
        ],
        Carrier::DHL_EUROPLUS      => [
            self::ZONE_LOCAL => self::FROM_500,
            self::ZONE_BE    => self::FROM_500,
            self::ZONE_EU    => self::FROM_500,
            self::ZONE_ROW   => self::FROM_500,
        ],
        Carrier::DHL_PARCEL_CONNECT => [
            self::ZONE_EU  => self::FROM_500,
            self::ZONE_ROW => self::FROM_500,
        ],
        Carrier::UPS_STANDARD      => [
            self::ZONE_LOCAL => self::FROM_100,
        ],
        Carrier::GLS               => [
            self::ZONE_LOCAL => self::TEN_K,
            self::ZONE_EU    => self::TEN_K,
            self::ZONE_ROW   => self::TEN_K,
        ],
    ];

    /** @return int[] ascending; empty when the carrier offered no insurance in that zone */
    public static function forCarrierAndZone(string $carrier, string $zone): array
    {
        return self::TIERS[$carrier][$zone] ?? [];
    }

    /**
     * Rounds up to the nearest tier, or down to the largest when the amount exceeds it. Zero stays
     * zero, and an empty list returns the amount untouched.
     *
     * This is UpgradeData's historical rule, moved here rather than copied: two implementations of
     * it would let the migration and the shim drift apart.
     *
     * @param int[] $tiers
     */
    public static function snap(array $tiers, int $amount): int
    {
        if (0 === $amount) {
            return 0;
        }

        sort($tiers);

        $count = count($tiers);

        for ($i = 0; $i < $count; $i++) {
            if ($amount <= $tiers[$i]) {
                return $tiers[$i];
            }

            if ($i === $count - 1) {
                return $tiers[$i];
            }
        }

        return $amount;
    }

    /**
     * An amount beta.15's setInsurance() will accept for this carrier and zone. Unlike snap(), an
     * empty list answers 0 rather than passing the amount through, because an empty list is exactly
     * the case where beta.15 throws.
     */
    public static function acceptableForSdk(string $carrier, string $zone, int $amount): int
    {
        $tiers = self::forCarrierAndZone($carrier, $zone);

        return [] === $tiers ? 0 : self::snap($tiers, $amount);
    }

    /** Which frozen zone a destination country falls in, matching beta.15's own dispatch. */
    public static function zoneFor(?string $countryCode): string
    {
        if (CountryCode::CC_BE === $countryCode) {
            return self::ZONE_BE;
        }

        if (null === $countryCode || CountryCode::CC_NL === $countryCode) {
            return self::ZONE_LOCAL;
        }

        return CountryCode::isEu($countryCode) ? self::ZONE_EU : self::ZONE_ROW;
    }
}
