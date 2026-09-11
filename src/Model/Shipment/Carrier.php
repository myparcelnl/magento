<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierGLS;
use MyParcelNL\Sdk\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\Model\Carrier\CarrierTrunkrs;
use MyParcelNL\Sdk\Model\Carrier\CarrierUPSStandard;
use Throwable;

/**
 * Carrier names and labels.
 *
 * Same split as PackageType and DeliveryType: the lowercase names are ours and are config path
 * segments, so they cannot follow the SDK. V2_NAMES_MAP translates them to the Core API vocabulary
 * a capabilities response speaks.
 *
 */
final class Carrier
{
    use MapsV2Names;

    // The SDK owns a carrier's name and label; this list is only which of them the module has
    // admin settings and insurance virtual types for. The SDK ships more (bpost, brt, inpost,
    // posteitaliane, upsexpresssaver) and an account's real set comes from capabilities.
    public const POSTNL             = CarrierPostNL::NAME;
    public const DHL_FOR_YOU        = CarrierDHLForYou::NAME;
    public const DHL_EUROPLUS       = CarrierDHLEuroplus::NAME;
    public const DHL_PARCEL_CONNECT = CarrierDHLParcelConnect::NAME;
    public const UPS_STANDARD       = CarrierUPSStandard::NAME;
    public const DPD                = CarrierDPD::NAME;
    public const GLS                = CarrierGLS::NAME;
    public const TRUNKRS            = CarrierTrunkrs::NAME;

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

    public const LOCAL_COUNTRY_MAP
        = [
            self::POSTNL             => CountryCode::CC_NL,
            self::DHL_FOR_YOU        => CountryCode::CC_NL,
            self::DHL_EUROPLUS       => CountryCode::CC_NL,
            self::DHL_PARCEL_CONNECT => CountryCode::CC_NL,
            self::UPS_STANDARD       => CountryCode::CC_NL,
            self::DPD                => CountryCode::CC_BE,
            self::GLS                => CountryCode::CC_NL,
            self::TRUNKRS            => CountryCode::CC_NL,
        ];

    /** Falls back to NL, which is what every carrier but DPD answers and what the old code hardcoded. */
    public static function localCountryCodeFor(?string $name): string
    {
        return self::LOCAL_COUNTRY_MAP[$name] ?? CountryCode::CC_NL;
    }

    /** The carrier's label, from the SDK. Its own name back for one the SDK does not know. */
    public static function humanFor(string $name): string
    {
        try {
            return CarrierFactory::createFromName($name)->getHuman();
        } catch (Throwable $e) {
            return $name;
        }
    }
}
