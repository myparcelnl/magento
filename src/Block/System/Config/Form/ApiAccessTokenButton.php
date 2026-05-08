<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MyParcelNL\Magento\Service\Settings;

class ApiAccessTokenButton extends Template
{
    protected $_template = 'MyParcelNL_Magento::api_access_token_button.phtml';

    private Settings $settings;

    public function __construct(
        Context  $context,
        Settings $settings,
        array    $data = []
    ) {
        parent::__construct($context, $data);
        $this->settings = $settings;
    }

    public function getCurrentScope(): array
    {
        return $this->settings->getCurrentScopeFromRequest($this->getRequest());
    }

    public function hasTokenAtCurrentScope(): bool
    {
        [$scope, $scopeId] = $this->getCurrentScope();
        return $this->settings->hasRowAtScope($this->getTokenPath(), $scope, $scopeId);
    }

    public function getTokenPath(): string
    {
        $field = (array) $this->getData('field');
        return $field['path'] ?? 'myparcelnl_magento_general/api_access_token';
    }

    public function getFieldHtmlId(): string
    {
        return str_replace('/', '_', $this->getTokenPath());
    }

    public function getAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/apiaccesstoken/generate');
    }
}
