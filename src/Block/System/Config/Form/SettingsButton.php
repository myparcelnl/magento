<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;

class SettingsButton extends Field
{
    public const BUTTON_ID = 'myparcel-account-settings-button';

    /**
     * Path to template file in theme.
     *
     * @var string
     */
    protected $_template = 'MyParcelNL_Magento::settings_button.phtml';

    private Settings $settings;

    public function __construct(Context $context, Settings $settings, array $data = [])
    {
        parent::__construct($context, $data);
        $this->settings = $settings;
    }

    /**
     * The scope the admin is editing, resolved here rather than parsed out of the URL in JavaScript.
     *
     * @return array{0: string, 1: int}
     */
    public function getCurrentScope(): array
    {
        return $this->settings->getCurrentScopeFromRequest($this->getRequest());
    }

    /** The api key input this button depends on, by the id the config form gives it. */
    public function getApiKeyFieldId(): string
    {
        return str_replace('/', '_', Config::XML_PATH_API_KEY);
    }

    /**
     * Retrieve HTML markup for given form element
     *
     * @param  \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()
            ->unsCanUseWebsiteValue()
            ->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Retrieve element HTML markup
     *
     * @param  \Magento\Framework\Data\Form\Element\AbstractElement $element
     *
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * Return ajax url for import account configuration button
     *
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/settings/CarrierConfigurationImport');
    }

    /**
     * @return mixed
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()
            ->createBlock(Button::class)
            ->setData([
                'id'    => self::BUTTON_ID,
                'label' => __('Import'),
            ]);
        return $button->toHtml();
    }
}

