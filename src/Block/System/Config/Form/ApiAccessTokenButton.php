<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\System\Config\Form;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
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

    public function getRevokeAjaxUrl(): string
    {
        return $this->_urlBuilder->getUrl('myparcel/apiaccesstoken/revoke');
    }

    /**
     * Cascade-direction warning specific to the admin's current scope.
     * The text changes per scope so the admin sees which other tokens this Generate click shrinks.
     */
    public function getScopeComment(): string
    {
        [$scope] = $this->getCurrentScope();

        switch ($scope) {
            case ScopeInterface::SCOPE_STORES:
                return (string) __(
                    'Covers only this store. Issuing this token removes this store from the default-scope token\'s view and from any parent-website token\'s view, it is now exclusively owned by this store-view-scoped token.'
                );
            case ScopeInterface::SCOPE_WEBSITES:
                return (string) __(
                    'Covers every store-view in this website not tokened separately at store-view scope. Issuing this token removes these stores from the default-scope token\'s view; any store-view in this website with its own dedicated token remains invisible to this token.'
                );
            case ScopeConfigInterface::SCOPE_TYPE_DEFAULT:
            default:
                return (string) __('Covers every store not tokened separately at website or store-view scope.');
        }
    }
}
