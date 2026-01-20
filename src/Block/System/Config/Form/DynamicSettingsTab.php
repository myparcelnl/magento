<?php
/**
 * Render dynamic settings tab block in system configuration.
 */

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use MyParcelNL\Magento\Block\Adminhtml\DynamicSettings;

class DynamicSettingsTab extends \Magento\Backend\Block\AbstractBlock implements
    \Magento\Framework\Data\Form\Element\Renderer\RendererInterface
{
    /**
     * Render fieldset html
     *
     * @param AbstractElement $element
     *
     * @return string
     * @throws LocalizedException
     */
    public function render(AbstractElement $element): string
    {
        return $this->getLayout()
                    ->createBlock(DynamicSettings::class)
                    ->setTemplate('MyParcelNL_Magento::dynamic_settings.phtml')
                    ->toHtml();
    }
}
