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
 * The bound is per account and scope, so it is emitted at render time, not fixed in the Catalogue.
 * It is advisory: contract definitions carry no country, and ShipmentOptionsResolver clamps per
 * destination. With no bound, the field still accepts a number and insurance stays on.
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

    /** Check whether the field inherits its value, as dynamic_settings.phtml does for its controls. */
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
     * Get the Magento validation classes for this field.
     *
     * For optional insurance the span starts at zero; the save observer refuses the gap below the
     * minimum, because `validate-number-range` can express only one span.
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
