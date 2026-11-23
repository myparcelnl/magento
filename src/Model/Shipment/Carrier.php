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
use MyParcelNL\Sdk\Services\CountryCodes;
use Throwable;

/**
 * Carrier names and labels. V2_NAMES_MAP translates the names to the Core API vocabulary that a
 * capabilities response speaks.
 */
class Carrier
{
    use MapsV2Names;

    // The SDK owns a carrier's name and label; this list is only which of them the module has admin
    // settings for. The SDK ships more (bpost, brt, inpost, posteitaliane, upsexpresssaver), and an
    // account's real set comes from capabilities.
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

    /** The carriers whose local country is not NL. */
    private const LOCAL_COUNTRY_MAP = [self::DPD => CountryCodes::CC_BE];

    /** Get the carrier's local country: BE for DPD, NL for every other carrier. */
    public static function localCountryCodeFor(?string $name): string
    {
        return self::LOCAL_COUNTRY_MAP[$name] ?? CountryCodes::CC_NL;
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
