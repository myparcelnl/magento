<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\Services\CountryCodes;

class DeliveryCostsMatrix extends Field
{
    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelNL_Magento::delivery_costs_matrix.phtml';

    public function getCarriers(): array
    {
        $carriers = [];
        foreach (Carrier::ALLOWED_CARRIER_CLASSES as $carrierClass) {
            $carrier                       = new $carrierClass();
            $carriers[$carrier->getName()] = $carrier->getHuman();
        }
        return $carriers;
    }

    public function getPackageTypes(): array
    {
        return [
            AbstractConsignment::PACKAGE_TYPE_PACKAGE       => __('Package'),
            AbstractConsignment::PACKAGE_TYPE_MAILBOX       => __('Mailbox'),
            AbstractConsignment::PACKAGE_TYPE_LETTER        => __('Letter'),
            AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP => __('Digital stamp'),
            AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL => __('Small package'),
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
     * @param AbstractElement $element mandatory parameter we do not need here
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
            'Maximum weight' => __('Maximum weight'),
            'Country part of' => __('Country part of'),
            'New rule' => __('New rule'),
            'Show or hide JSON textarea' => __('Show or hide JSON textarea'),
        ];
    }
}
