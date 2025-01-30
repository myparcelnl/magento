<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelNL\Magento\Block\Sales\NewShipmentForm;
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
        foreach (NewShipmentForm::ALLOWED_CARRIER_CLASSES as $carrierClass) { // TODO move constant from NewShipmentForm
            $carrier = new $carrierClass();
            $carriers[$carrier->getName()] = $carrier->getHuman();
        }
        return $carriers;
    }

    public function getPackageTypes(): array
    {
        return NewShipmentForm::PACKAGE_TYPE_HUMAN_MAP; // TODO move constant from NewShipmentForm
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
     * @param  \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }
}