<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelNL\Magento\Model\Shipment\Carrier as ShipmentCarrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Service\AccountSettings\ContractDefinitions;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;
use MyParcelNL\Sdk\Services\CountryCodes;

class DeliveryCostsMatrix extends Field
{
    private ContractDefinitions $contractDefinitions;
    private Settings            $settings;

    public function __construct(
        Context             $context,
        ContractDefinitions $contractDefinitions,
        Settings            $settings,
        array               $data = []
    )
    {
        parent::__construct($context, $data);
        $this->contractDefinitions = $contractDefinitions;
        $this->settings            = $settings;
    }

    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelNL_Magento::delivery_costs_matrix.phtml';

    /**
     * Every carrier the module has settings for that this account also has a contract for.
     *
     * This form configures rates with no shipment in hand, so the filter is contract definitions
     * rather than the capabilities endpoint (DR-19). Unresolvable bounds show every configured
     * carrier: a merchant must not lose the ability to price a lane because we could not confirm it
     * (FR-000010).
     */
    public function getCarriers(): array
    {
        [$scopeName, $scopeId] = $this->settings->getCurrentScopeFromRequest($this->getRequest());

        $contracted = $this->contractDefinitions->forScope($scopeName, $scopeId);
        $carriers   = [];

        foreach (array_keys(Config::CARRIERS_XML_PATH_MAP) as $carrierName) {
            if (! $contracted->isPermissive() && ! in_array($carrierName, $contracted->carriers(), true)) {
                continue;
            }

            $carriers[$carrierName] = ShipmentCarrier::humanFor($carrierName);
        }

        return $carriers;
    }

    public function getPackageTypes(): array
    {
        return [
            PackageType::PACKAGE       => __('Package'),
            PackageType::MAILBOX       => __('Mailbox'),
            PackageType::LETTER        => __('Letter'),
            PackageType::DIGITAL_STAMP => __('Digital stamp'),
            PackageType::PACKAGE_SMALL => __('Small package'),
        ];
    }

    public function getCountryCodes(): array
    {
        return CountryCodes::ALL;
    }

    public function getCountryParts(): array
    {
        return [CountryCodes::ZONE_EU, CountryCodes::ZONE_ROW];
    }

    /**
     * Retrieve element HTML markup, called from Magento
     *
     * @param AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        // combine the default HTML with the custom HTML for scoping
        $defaultHtml = parent::_getElementHtml($element);

        $customHtml = $this->_toHtml();

        return $defaultHtml . $customHtml;
    }

    public function getCssUrl(): string
    {
        return $this->_assetRepo->createAsset('MyParcelNL_Magento::css/config/delivery_costs_matrix/style.css')->getUrl();
    }

    public function getTranslations(): array {
        return [
            'Rule name' => __('Rule name'),
            'Price' => __('Price'),
            'No rules defined. Click Add rule to create a new rule.' => __('No rules defined. Click Add rule to create a new rule.'),
            'Add Rule' => __('Add Rule'),
            'Condition' => __('Condition'),
            'Value' => __('Value'),
            'Toggle conditions' => __('Toggle conditions'),
            'Remove rule' => __('Remove rule'),
            'Add condition' => __('Add condition'),
            'Remove condition' => __('Remove condition'),
            'Select a condition' => __('Select a condition'),
            'Carrier name' => __('Carrier name'),
            'Country' => __('Country'),
            'Package type' => __('Package type'),
            'Maximum weight (in grams)' => __('Maximum weight (in grams)'),
            'Country part of' => __('Country part of'),
            'New rule' => __('New rule'),
            'Show or hide JSON textarea' => __('Show or hide JSON textarea'),
            'Invalid JSON in textarea' => __('Invalid JSON in textarea'),
        ];
    }
}
