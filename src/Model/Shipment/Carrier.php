<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

/**
 * Carrier names and labels.
 *
 * Same split as PackageType and DeliveryType: the lowercase names are ours and are config path
 * segments, so they cannot follow the SDK. V2_NAMES_MAP translates them to the Core API vocabulary
 * a capabilities response speaks.
 *
 * HUMAN_MAP is module-owned because a capabilities response carries carrier names, not labels.
 */
final class Carrier
{
    public const POSTNL             = 'postnl';
    public const DHL_FOR_YOU        = 'dhlforyou';
    public const DHL_EUROPLUS       = 'dhleuroplus';
    public const DHL_PARCEL_CONNECT = 'dhlparcelconnect';
    public const UPS_STANDARD       = 'upsstandard';
    public const DPD                = 'dpd';
    public const GLS                = 'gls';
    public const TRUNKRS            = 'trunkrs';

    public const V2_NAMES_MAP
        = [
            self::POSTNL             => 'POSTNL',
            self::DHL_FOR_YOU        => 'DHL_FOR_YOU',
            self::DHL_EUROPLUS       => 'DHL_EUROPLUS',
            self::DHL_PARCEL_CONNECT => 'DHL_PARCEL_CONNECT',
            self::UPS_STANDARD       => 'UPS_STANDARD',
            self::DPD                => 'DPD',
            self::GLS                => 'GLS',
            self::TRUNKRS            => 'TRUNKRS',
        ];

    public const HUMAN_MAP
        = [
            self::POSTNL             => 'PostNL',
            self::DHL_FOR_YOU        => 'DHL For You',
            self::DHL_EUROPLUS       => 'DHL Europlus',
            self::DHL_PARCEL_CONNECT => 'DHL Parcel Connect',
            self::UPS_STANDARD       => 'UPS Standard',
            self::DPD                => 'DPD',
            self::GLS                => 'GLS',
            self::TRUNKRS            => 'Trunkrs',
        ];

    public static function toV2Name(string $name): ?string
    {
        return self::V2_NAMES_MAP[$name] ?? null;
    }

    /** Null for a carrier the module has no name for; the caller logs it rather than inventing one. */
    public static function fromV2Name(string $v2Name): ?string
    {
        $name = array_search($v2Name, self::V2_NAMES_MAP, true);

        return false === $name ? null : $name;
    }

    public static function humanFor(string $name): string
    {
        return self::HUMAN_MAP[$name] ?? $name;
    }
}
