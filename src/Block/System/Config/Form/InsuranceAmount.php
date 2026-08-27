<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MyParcelNL\Magento\Model\Settings\InsuranceAmountSetting;
use MyParcelNL\Magento\Model\Shipment\Capabilities\InsuranceRange;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;

/**
 * Renders one insurance amount as a number field bounded by the account's contract.
 *
 * It exists because `etc/dynamic_settings.json` is a static file and the bound is per account and per
 * scope: `dynamic_settings.phtml` can only inject a fixed `validate` class, so a range like
 * `number-range-0-5000` has to be emitted at render time.
 *
 * The bound is **advisory** (DR-19). Contract definitions carry no country, so this states what the
 * carrier's contract allows at all; the amount a given parcel is actually insured for is clamped
 * against its own destination in ShipmentOptionsResolver. With no bound resolvable the field still
 * accepts a number — insurance is not switched off because we could not confirm its limits.
 */
class InsuranceAmount extends Template implements NoteProviderInterface
{
    protected $_template = 'MyParcelNL_Magento::insurance_amount.phtml';

    private ContractDefinitions $contractDefinitions;
    private Settings            $settings;
    private Config              $config;

    private ?InsuranceRange $range     = null;
    private bool            $resolved  = false;

    public function __construct(
        Context             $context,
        ContractDefinitions $contractDefinitions,
        Settings            $settings,
        Config              $config,
        array               $data = []
    ) {
        parent::__construct($context, $data);
        $this->contractDefinitions = $contractDefinitions;
        $this->settings            = $settings;
        $this->config              = $config;
    }

    public function getPath(): string
    {
        $field = (array) $this->getData('field');

        return (string) ($field['path'] ?? '');
    }

    public function getFieldHtmlId(): string
    {
        return str_replace('/', '_', $this->getPath());
    }

    public function getFieldName(): string
    {
        return sprintf('config[%s]', $this->getPath());
    }

    /** @return array{0: string, 1: int} */
    public function getCurrentScope(): array
    {
        return $this->settings->getCurrentScopeFromRequest($this->getRequest());
    }

    public function getValue(): string
    {
        [$scopeName, $scopeId] = $this->getCurrentScope();

        return (string) ($this->config->getScopedConfig($this->getPath(), $scopeName, $scopeId) ?? '');
    }

    /**
     * Mirrors what dynamic_settings.phtml does for its own controls: a scope inheriting its value
     * shows it greyed out until the admin unticks *Use Default*. hasOwnValue() already answers true
     * at default scope, so there is no special case here.
     */
    public function isDisabled(): bool
    {
        [$scopeName, $scopeId] = $this->getCurrentScope();

        return ! $this->settings->hasOwnValue($this->getPath(), $scopeName, $scopeId);
    }

    /** Null when no bound could be resolved; the field then validates as a plain number. */
    public function getRange(): ?InsuranceRange
    {
        if (! $this->resolved) {
            $this->resolved = true;
            $this->range    = $this->resolveRange();
        }

        return $this->range;
    }

    /**
     * The Magento validation classes for this field.
     *
     * `validate-number-range` expresses one span, and the permitted set is a span plus zero when
     * insurance is optional. So the span starts at zero for an optional contract and the save
     * observer catches the gap between zero and the minimum; for a compulsory one the span is the
     * range itself and zero is refused in the browser.
     */
    public function getValidationClass(): string
    {
        $classes = 'validate-number validate-zero-or-greater';
        $range   = $this->getRange();

        if (null !== $range) {
            $classes .= sprintf(
                ' validate-number-range number-range-%d-%d',
                $range->lowestAccepted(),
                $range->max()
            );
        }

        return $classes;
    }

    /** States the contract range beside the field; rendered by dynamic_settings.phtml, not here. */
    public function getNote(): string
    {
        $range = $this->getRange();

        if (null === $range) {
            return (string) __(
                'The permitted range could not be retrieved, so any amount is accepted here and MyParcel decides. Enter 0 to switch insurance off.'
            );
        }

        if ($range->isRequired()) {
            return (string) __(
                'Your contract allows € %1 to € %2. Insurance is required for this carrier, so it cannot be switched off.',
                $range->min(),
                $range->max()
            );
        }

        return (string) __(
            'Your contract allows € %1 to € %2. Enter 0 to switch insurance off.',
            $range->min(),
            $range->max()
        );
    }

    private function resolveRange(): ?InsuranceRange
    {
        $carrier = InsuranceAmountSetting::carrierFor($this->getPath());

        if (null === $carrier) {
            return null;
        }

        [$scopeName, $scopeId] = $this->getCurrentScope();

        // Same lookup Validator\InsuranceAmount uses, so what this field states is what the save
        // enforces.
        return $this->contractDefinitions->insuranceRangeFor($carrier, $scopeName, $scopeId);
    }
}
