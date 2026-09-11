<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Service to read dynamic settings configuration.
 *
 * This class reads settings from a JSON configuration file and provides methods
 * to filter which settings are available for the current user/context.
 * TODO this functionality must be integrated into the capabilities system (INT-1289)
 */
class Settings
{
    private ModuleDirReader      $moduleDirReader;
    private Json                 $json;
    private ?array               $settingsCache = null;
    private CollectionFactory    $scopeCollectionFactory;

    public function __construct(
        ModuleDirReader      $moduleDirReader,
        Json                 $json,
        CollectionFactory    $scopeCollectionFactory
    )
    {
        $this->moduleDirReader        = $moduleDirReader;
        $this->json                   = $json;
        $this->scopeCollectionFactory = $scopeCollectionFactory;
    }

    /**
     * Get all settings configuration from JSON file.
     *
     * @return array
     */
    public function getSettings(): array
    {
        if ($this->settingsCache === null) {
            $this->settingsCache = $this->loadSettingsFromFile();
        }

        return $this->settingsCache;
    }

    /**
     * Get all sections.
     *
     * @return array
     */
    public function getSections(): array
    {
        return $this->getSettings()['sections'] ?? [];
    }

    /**
     * Get all field paths from the configuration.
     *
     * @return array
     */
    public function getAllFieldPaths(): array
    {
        $paths = [];

        foreach ($this->getSections() as $section) {
            foreach ($section['groups'] ?? [] as $group) {
                foreach ($group['fields'] ?? [] as $field) {
                    $paths[] = $field['path'];
                }
            }
        }

        return $paths;
    }

    /**
     * Check if a field should be visible for the given scope.
     *
     * @param array  $field
     * @param string $scopeName 'default', 'websites', or 'stores'
     * @return bool
     */
    public function isFieldVisibleInScope(array $field, string $scopeName): bool
    {
        switch ($scopeName) {
            case ScopeConfigInterface::SCOPE_TYPE_DEFAULT:
                return $field['showInDefault'] ?? false;
            case ScopeInterface::SCOPE_WEBSITES:
                return $field['showInWebsite'] ?? false;
            case ScopeInterface::SCOPE_STORES:
                return $field['showInStore'] ?? false;
            default:
                return false;
        }
    }

    /**
     * Resolve the admin's current scope from request params.
     *
     * @return array{0: string, 1: int} [scopeName, scopeId]
     */
    public function getCurrentScopeFromRequest(RequestInterface $request): array
    {
        if (($storeId = $request->getParam('store'))) {
            return [ScopeInterface::SCOPE_STORES, (int) $storeId];
        }
        if (($websiteId = $request->getParam('website'))) {
            return [ScopeInterface::SCOPE_WEBSITES, (int) $websiteId];
        }
        return [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
    }

    /**
     * Partition-aware: true if and only if a row exists at the exact (scope, scopeId) for this path.
     * Unlike hasOwnValue() this does NOT short-circuit for default scope.
     */
    public function hasRowAtScope(string $path, string $scope, int $scopeId): bool
    {
        $collection = $this->scopeCollectionFactory->create()
            ->addFieldToFilter('path', $path)
            ->addFieldToFilter('scope', $scope)
            ->addFieldToFilter('scope_id', $scopeId);

        return $collection->getSize() > 0;
    }

    /**
     * The values stored at exactly this (scope, scopeId), keyed by path.
     *
     * Row coordinates, never inheritance: a path missing from the result has no row here, which is
     * not the same as having no value. A save compares against this to skip fields nobody changed,
     * and a cascaded read would answer "unchanged" for a scope that is only inheriting — which is
     * precisely the case that has to be written.
     *
     * One query for the whole form, so the comparison costs less than the writes it avoids.
     *
     * @param  string[] $paths
     * @return array<string, string|null>
     */
    public function storedValuesAtScope(array $paths, string $scope, int $scopeId): array
    {
        if ([] === $paths) {
            return [];
        }

        $collection = $this->scopeCollectionFactory->create()
            ->addFieldToFilter('path', ['in' => array_values(array_unique($paths))])
            ->addFieldToFilter('scope', $scope)
            ->addFieldToFilter('scope_id', $scopeId);

        $values = [];

        foreach ($collection as $row) {
            $values[(string) $row->getData('path')] = $row->getData('value');
        }

        return $values;
    }

    /**
     * Inheritance-aware: default scope always "owns" its value (config.xml fallback),
     * otherwise true if and only if an override row exists at the exact (scope, scopeId).
     */
    public function hasOwnValue(string $path, string $scopeName = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ?int $scopeId = null): bool
    {
        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scopeName) {
            return true;
        }

        return $this->hasRowAtScope($path, $scopeName, (int) $scopeId);
    }

    /**
     * Load settings from the JSON configuration file.
     *
     * @return array
     */
    private function loadSettingsFromFile(): array
    {
        $moduleDir = $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, 'MyParcelNL_Magento');
        $filePath  = $moduleDir . '/dynamic_settings.json';

        if (! file_exists($filePath)) {
            return ['sections' => []];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return ['sections' => []];
        }

        try {
            return $this->json->unserialize($content);
        } catch (\Exception $e) {
            return ['sections' => []];
        }
    }
}
