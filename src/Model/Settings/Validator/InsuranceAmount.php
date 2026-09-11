<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Validator;

use Magento\Framework\Phrase;
use MyParcelNL\Magento\Model\Settings\InsuranceAmountSetting;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;

/**
 * Keeps a saved insurance amount to a whole number of euros inside the account's contract range.
 *
 * The browser states the same bound, but a direct POST bypasses it, so this is where it is enforced.
 * It rejects rather than clamps: clamping belongs to export, where the real destination is known, and
 * silently rewriting what an admin typed in front of them would be worse than telling them.
 *
 * A bound that cannot be resolved lets the value through — insurance is not switched off because we
 * could not confirm its limits (FR-000010).
 */
class InsuranceAmount implements SettingValidatorInterface
{
    private ContractDefinitions $contractDefinitions;

    public function __construct(ContractDefinitions $contractDefinitions)
    {
        $this->contractDefinitions = $contractDefinitions;
    }

    public function handles(string $path): bool
    {
        return null !== InsuranceAmountSetting::carrierFor($path);
    }

    public function validate(string $path, $value, string $scopeName, int $scopeId): ?Phrase
    {
        $carrier = InsuranceAmountSetting::carrierFor($path);

        if (null === $carrier) {
            return null;
        }

        $entered = is_scalar($value) ? trim((string) $value) : '';

        // A cleared field reads as 0 everywhere downstream, so it is judged as 0 rather than waved
        // through — otherwise clearing the box would be a way around a contract that requires
        // insurance.
        $amount = '' === $entered ? '0' : $entered;

        if (! preg_match('/^\d+$/', $amount)) {
            return __('Insurance amount "%1" was not saved: enter a whole number of euros.', $entered);
        }

        $range = $this->contractDefinitions->insuranceRangeFor($carrier, $scopeName, $scopeId);

        if (null === $range || $range->allows((int) $amount)) {
            return null;
        }

        return __(
            'Insurance amount %1 was not saved: your contract allows € %2 to € %3.',
            (int) $amount,
            $range->min(),
            $range->max()
        );
    }
}
