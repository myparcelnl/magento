<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings;

use MyParcelNL\Magento\Model\Shipment\Carrier;

/**
 * The carriers an account may send a mailbox parcel abroad with, read from its general settings.
 *
 * The API names the flag `{carrier}_mailbox_international`, and the carrier derives from its prefix
 * the way Carrier::fromV2Name() derives one, so `dhl_for_you_…` reads as `dhlforyou`. A flag whose
 * prefix the SDK does not know as a carrier is ignored, never guessed.
 */
final class InternationalMailbox
{
    private const SUFFIX = '_mailbox_international';

    /**
     * @param  array<string, mixed> $generalSettings the stored `account.general_settings`
     * @return string[] module carrier names whose flag is on
     */
    public static function carriersIn(array $generalSettings): array
    {
        $carriers = [];

        foreach ($generalSettings as $key => $value) {
            $key    = (string) $key;
            $prefix = substr($key, 0, -strlen(self::SUFFIX));

            // Read as the SDK's GeneralSettings reads its booleans.
            if (! $value || '' === $prefix || self::SUFFIX !== substr($key, -strlen(self::SUFFIX))) {
                continue;
            }

            $carrier = Carrier::fromV2Name($prefix);

            if (null !== Carrier::toV2Name($carrier)) {
                $carriers[] = $carrier;
            }
        }

        return $carriers;
    }
}
