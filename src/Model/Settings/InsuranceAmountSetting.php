<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Service\Config;

/**
 * Recognises an insurance amount setting by its config path, and says which carrier it configures.
 *
 * The zone in a field's name (`local`, `belgium`, `eu`, `row`) is a merchant's own cap per destination
 * zone and carries no bound of its own (contract definitions have no country), so the carrier
 * is the whole answer.
 */
class InsuranceAmountSetting
{
    public const LOCAL   = 'insurance_local_amount';
    public const BELGIUM = 'insurance_belgium_amount';
    public const EU      = 'insurance_eu_amount';
    public const ROW     = 'insurance_row_amount';

    private const FIELDS = [self::LOCAL, self::BELGIUM, self::EU, self::ROW];

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
