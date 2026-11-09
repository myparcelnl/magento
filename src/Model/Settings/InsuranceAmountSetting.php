<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Model\Shipment\CountryCode;
use MyParcelNL\Magento\Service\Config;

/**
 * The insurance cap settings: which zones an account sets a cap for, which zone a destination falls
 * in, and which carrier a cap's config path configures.
 *
 * Contract definitions have no country, so the zone carries no bound of its own. Only the carrier does.
 */
final class InsuranceAmountSetting
{
    /** Belgium has a zone of its own only for an account at home in NL. */
    private const ZONES_AT_HOME_IN_NL = ['local', 'belgium', 'eu', 'row'];
    private const ZONES               = ['local', 'eu', 'row'];

    public static function zonesFor(?Proposition $proposition): array
    {
        return $proposition && CountryCode::CC_NL === $proposition->getCountryCode()
            ? self::ZONES_AT_HOME_IN_NL
            : self::ZONES;
    }

    /** Get the cap field a destination reads. No destination is the account's own country. */
    public static function fieldFor(?string $destination, ?Proposition $proposition): string
    {
        $home = ($proposition ?? Proposition::default())->getCountryCode();

        if (null === $destination || $home === $destination) {
            $zone = 'local';
        } elseif (CountryCode::CC_BE === $destination && in_array('belgium', self::zonesFor($proposition), true)) {
            $zone = 'belgium';
        } else {
            $zone = CountryCode::isEu($destination) ? 'eu' : 'row';
        }

        return self::fieldOf($zone);
    }

    public static function fieldOf(string $zone): string
    {
        return "insurance_{$zone}_amount";
    }

    /** Get the carrier whose insurance this path configures, or null for any other path. */
    public static function carrierFor(string $path): ?string
    {
        $segments = explode('/', $path);

        if (! in_array(end($segments), array_map([self::class, 'fieldOf'], self::ZONES_AT_HOME_IN_NL), true)) {
            return null;
        }

        return Config::carrierFromPath($path);
    }
}
