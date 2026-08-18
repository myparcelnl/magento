<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment;

use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * Country codes and the EU/ROW zone question.
 *
 * The EU list is not the one the old SDK used: it gains MT and loses XK, so those two countries
 * change customs behaviour. An unknown country counts as ROW. See DR-9.
 */
final class CountryCode
{
    public const CC_NL = CountryCodes::CC_NL;
    public const CC_BE = CountryCodes::CC_BE;

    public const EU_COUNTRIES = CountryCodes::EU_COUNTRIES;

    public static function isEu(?string $countryCode): bool
    {
        return null !== $countryCode && in_array($countryCode, self::EU_COUNTRIES, true);
    }

    public static function isRow(?string $countryCode): bool
    {
        return ! self::isEu($countryCode);
    }
}
