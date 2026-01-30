<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;

/**
 * Block for rendering dynamic settings form.
 */
class DynamicSettings extends Template
{
    protected $_template = 'MyParcelNL_Magento::dynamic_settings.phtml';

    private Settings               $settings;
    private ObjectManagerInterface $objectManager;
    private Config                 $config;

    private ?array $currentScope = null;

    public function __construct(
        ObjectManagerInterface $objectManager,
        Context                $context,
        Config                 $config,
        Json                   $json,
        Settings               $settings,
        array                  $data = []
    )
    {
        parent::__construct($context, $data);
        $this->objectManager = $objectManager;
        $this->config        = $config;
        $this->json          = $json;
        $this->settings      = $settings;
    }

    /**
     * Get the url of the stylesheet
     *
     * @return string
     * @throws LocalizedException
     */
    public function getCssUrl(): string
    {
        return $this->_assetRepo->createAsset('MyParcelNL_Magento::css/config/dynamic_settings/style.css')->getUrl();
    }

    /**
     * Get all sections from the settings configuration.
     *
     * @return array
     */
    public function getSections(): array
    {
        return $this->settings->getSections();
    }

    /**
     * Get the current scope type name.
     *
     * @return string 'default', 'websites', or 'stores'
     */
    public function getCurrentScopeName(): string
    {
        return $this->getCurrentScope()[0];
    }

    /**
     * Determine the current scope from request parameters.
     *
     * @return array indexed array holding 'name' (at index 0) and 'id' (at index 1) of the current scope
     */
    public function getCurrentScope(): array
    {
        if (! isset($this->currentScope)) {
            $request = $this->getRequest();

            if (($storeId = $request->getParam('store'))) {
                $this->currentScope = [ScopeInterface::SCOPE_STORES, (int) $storeId];
            } elseif (($websiteId = $request->getParam('website'))) {
                $this->currentScope = [ScopeInterface::SCOPE_WEBSITES, (int) $websiteId];
            } else {
                $this->currentScope = [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
            }
        }

        return $this->currentScope;
    }

    /**
     * @return array
     */
    public function getWebsites(): array
    {
        return $this->_storeManager->getWebsites();
    }

    /**
     * @return array
     */
    public function getStores(): array
    {
        return $this->_storeManager->getStores();
    }

    /**
     * Check if a field should be visible in the current scope.
     *
     * @param array $field
     * @return bool
     */
    public function isFieldVisibleInCurrentScope(array $field): bool
    {
        return $this->settings->isFieldVisibleInScope($field, $this->getCurrentScopeName());
    }

    /**
     * Get the current value for a field.
     *
     * @param array $field
     * @return mixed
     */
    public function getFieldValue(array $field)
    {
        return $this->getConfigValueByPath($field['path']);
    }

    /**
     * Check if a field has a value explicitly set at the current scope.
     *
     * @param array $field
     * @return bool
     */
    public function hasOwnValue(array $field): bool
    {
        $path = $field['path'];
        [$scopeName, $scopeId] = $this->getCurrentScope();

        return $this->settings->hasOwnValue($path, $scopeName, $scopeId);
    }

    /**
     * Get the save URL for the form.
     *
     * @return string
     */
    public function getSaveUrl(): string
    {
        return $this->getUrl('myparcel/settings/save');
    }

    /**
     * Get options for a select field.
     *
     * @param array $field
     * @return array
     */
    public function getFieldOptions(array $field): array
    {
        if (! isset($field['source_model'])) {
            return [];
        }

        try {
            $sourceModel = $this->objectManager->get($field['source_model']);
            if (method_exists($sourceModel, 'toOptionArray')) {
                return $sourceModel->toOptionArray();
            }
        } catch (\Exception $e) {
            // Source model not found or error
        }

        return [];
    }

    /**
     * Render a custom frontend model block.
     *
     * @param string $frontendModel
     * @param array  $field
     * @return string
     */
    public function renderFrontendModel(string $frontendModel, array $field = []): string
    {
        try {
            $block = $this->getLayout()->createBlock($frontendModel);
            if ($block) {
                $block->setData('field', $field);

                return $block->toHtml();
            }
        } catch (\Throwable $e) {
            return "<div class='message message-error'>{$this->_escaper->escapeHtml($e->getMessage())}</div>";
        }

        return '';
    }

    /**
     * Get the unique HTML ID for a field.
     *
     * @param array $field
     * @return string
     */
    public function getFieldHtmlId(array $field): string
    {
        return str_replace('/', '_', $field['path']);
    }

    /**
     * Get the form field name for a field.
     *
     * @param array $field
     * @return string
     */
    public function getFieldName(array $field): string
    {
        return "config[{$field['path']}]";
    }

    /**
     * Check if dependencies are met for a field.
     *
     * @param array $field
     * @return bool
     */
    public function areDependenciesMet(array $field): bool
    {
        if (empty($field['depends'])) {
            return true;
        }

        foreach ($field['depends'] as $dependency) {
            $depPath  = $dependency['field'];
            $depValue = $dependency['value'];

            $currentValue = $this->getConfigValueByPath($depPath);

            if ((string) $currentValue !== (string) $depValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a config value by path for dependency checking.
     *
     * @param string $path
     * @return mixed
     */
    private function getConfigValueByPath(string $path)
    {
        [$scopeName, $scopeId] = $this->getCurrentScope();

        return $this->config->getScopedConfig($path, $scopeName, $scopeId);
    }

    /**
     * Get JSON-encoded dependencies for JavaScript.
     *
     * @param array $field
     * @return string
     */
    public function getDependenciesJson(array $field): string
    {
        if (! isset($field['depends']) || empty($field['depends'])) {
            return '[]';
        }

        $dependencies = [];
        foreach ($field['depends'] as $dependency) {
            $dependencies[] = [
                'field' => $this->getFieldHtmlId(['path' => $dependency['field']]),
                'value' => $dependency['value'],
            ];
        }

        return $this->json->serialize($dependencies);
    }
}
