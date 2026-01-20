<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Service to read and filter dynamic settings configuration.
 *
 * This class reads settings from a JSON configuration file and provides methods
 * to filter which settings are available for the current user/context.
 * In the future, this can be extended to fetch settings from an API.
 */
class DynamicSettingsConfig
{
    private ModuleDirReader      $moduleDirReader;
    private Json                 $json;
    private ScopeConfigInterface $scopeConfig;
    private ?array               $settingsCache = null;
    private CollectionFactory    $scopeCollectionFactory;

    public function __construct(
        ModuleDirReader      $moduleDirReader,
        Json                 $json,
        ScopeConfigInterface $scopeConfig,
        CollectionFactory    $scopeCollectionFactory
    )
    {
        $this->moduleDirReader        = $moduleDirReader;
        $this->json                   = $json;
        $this->scopeConfig            = $scopeConfig;
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
     * Get filtered settings based on availability.
     * This method can be extended to filter based on API response or other criteria.
     *
     * @param array|null $availablePaths Optional array of paths that should be shown.
     *                                   If null, all settings are returned.
     * @return array
     */
    public function getFilteredSettings(?array $availablePaths = null): array
    {
        $settings = $this->getSettings();

        if ($availablePaths === null) {
            return $settings;
        }

        $filteredSections = [];

        foreach ($settings['sections'] ?? [] as $section) {
            $filteredGroups = [];

            foreach ($section['groups'] ?? [] as $group) {
                $filteredFields = [];

                foreach ($group['fields'] ?? [] as $field) {
                    if (in_array($field['path'], $availablePaths, true)) {
                        $filteredFields[] = $field;
                    }
                }

                if (! empty($filteredFields) || isset($group['frontend_model'])) {
                    $group['fields']  = $filteredFields;
                    $filteredGroups[] = $group;
                }
            }

            if (! empty($filteredGroups)) {
                $section['groups']  = $filteredGroups;
                $filteredSections[] = $section;
            }
        }

        return ['sections' => $filteredSections];
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
     * Get a specific section by ID.
     *
     * @param string $sectionId
     * @return array|null
     */
    public function getSection(string $sectionId): ?array
    {
        foreach ($this->getSections() as $section) {
            if ($section['id'] === $sectionId) {
                return $section;
            }
        }
        return null;
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
     * @param string $scope 'default', 'websites', or 'stores'
     * @return bool
     */
    public function isFieldVisibleInScope(array $field, string $scope): bool
    {
        switch ($scope) {
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
     * Get the current config value for a field path.
     *
     * @param string   $path
     * @param string   $scope
     * @param int|null $scopeId
     * @return mixed
     */
    public function getConfigValue(string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ?int $scopeId = null)
    {
        if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
            return $this->scopeConfig->getValue($path);
        }

        return $this->scopeConfig->getValue($path, $scope, $scopeId);
    }

    /**
     * @param string   $path
     * @param string   $scope
     * @param int|null $scopeId
     * @return bool whether a specific value exists for the given scope (ie it is overriding)
     */
    public function hasOwnValue(string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ?int $scopeId = null): bool
    {
        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scope) {
            return true; // Default scope always "owns" its values
        }

        // Check if there's a specific value in the database for this scope
        $collection = $this->scopeCollectionFactory->create()
                                                   ->addFieldToFilter('path', $path)
                                                   ->addFieldToFilter('scope', $scope)
                                                   ->addFieldToFilter('scope_id', $scopeId)
        ;

        return $collection->getSize() > 0;
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
