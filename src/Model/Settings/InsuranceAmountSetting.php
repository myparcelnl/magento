<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Services\CountryCodes;

/**
 * The insurance cap settings: which zones an account sets a cap for, which zone a destination falls
 * in, and which carrier a cap's config path configures.
 *
 * The zone in a field's name is a merchant's own cap per destination zone and carries no bound of its
 * own (contract definitions have no country), so the carrier is the whole answer to the last one.
 */
class InsuranceAmountSetting
{
    public const LOCAL   = 'insurance_local_amount';
    public const BELGIUM = 'insurance_belgium_amount';
    public const EU      = 'insurance_eu_amount';
    public const ROW     = 'insurance_row_amount';

    /** Belgium has a zone of its own only for an account at home in NL. */
    private const ZONES_AT_HOME_IN_NL = ['local', 'belgium', 'eu', 'row'];
    private const ZONES               = ['local', 'eu', 'row'];

    private const FIELDS = [self::LOCAL, self::BELGIUM, self::EU, self::ROW];

    public static function zonesFor(?Proposition $proposition): array
    {
        return $proposition && CountryCodes::CC_NL === $proposition->getCountryCode()
            ? self::ZONES_AT_HOME_IN_NL
            : self::ZONES;
    }

    /** The cap field a destination reads. No destination is the account's own country. */
    public static function fieldFor(?string $destination, ?Proposition $proposition): string
    {
        $home = ($proposition ?? Proposition::default())->getCountryCode();

        if (null === $destination || $home === $destination) {
            $zone = 'local';
        } elseif (CountryCodes::CC_BE === $destination && in_array('belgium', self::zonesFor($proposition), true)) {
            $zone = 'belgium';
        } else {
            $zone = in_array($destination, CountryCodes::EU_COUNTRIES, true) ? 'eu' : 'row';
        }

        return "insurance_{$zone}_amount";
    }

    /** Get the carrier whose insurance this path configures, or null for any other path. */
    public static function carrierFor(string $path): ?string
    {
        $segments = explode('/', $path);

        if (! in_array(end($segments), self::FIELDS, true)) {
            return null;
        }

        return Config::carrierFromPath($path);
    }
}
