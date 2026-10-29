<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Validator;

use Magento\Framework\Phrase;
use MyParcelNL\Magento\Model\Shipment\Carrier;
use MyParcelNL\Magento\Service\Config;

/**
 * Refuses to switch on a carrier the module cannot export, so no order on it fails at label time.
 *
 * The form already renders these switches disabled. This stops a crafted post or a stale form.
 */
class ExportableCarrier implements SettingValidatorInterface
{
    private const FIELD = '#^[^/]+/(delivery|pickup)/active$#';

    public function handles(string $path): bool
    {
        return null !== Config::carrierFromPath($path) && 1 === preg_match(self::FIELD, $path);
    }

    public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase
    {
        $carrier = Config::carrierFromPath($path);

        if ('1' !== (string) $value || null === $carrier || Carrier::isExportable($carrier)) {
            return null;
        }

        return __(
            '%1 was not switched on: this carrier needs a module update before orders can ship with it.',
            Carrier::humanFor($carrier)
        );
    }
}
