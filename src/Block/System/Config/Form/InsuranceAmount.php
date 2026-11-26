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
 * The range is per account and scope, so it is emitted at render time, not fixed in the Catalogue.
 * It has no zone, because contract definitions carry no country; ShipmentOptionsResolver clamps per
 * destination. With no range, the field still accepts a number and insurance stays on.
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

    /** Get the config path of the field this block renders. */
    public function getPath(): string
    {
        $field = (array) $this->getData('field');

        return (string) ($field['path'] ?? '');
    }

    /** Get the HTML id of the field, from its config path. */
    public function getFieldHtmlId(): string
    {
        return str_replace('/', '_', $this->getPath());
    }

    /** Get the form name of the field, as config[<path>]. */
    public function getFieldName(): string
    {
        return sprintf('config[%s]', $this->getPath());
    }

    /**
     * Get the scope name and id the admin is editing.
     *
     * @return array{0: string, 1: int}
     */
    public function getCurrentScope(): array
    {
        return $this->settings->getCurrentScopeFromRequest($this->getRequest());
    }

    /** Get the value stored at the current scope, or ''. */
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

    /** Get the insurance range of the carrier, or null when none resolves; the field then validates as a plain number. */
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

        return $range->note();
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
