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
     * @param string   $path
     * @param string   $scopeName
     * @param int|null $scopeId
     * @return bool whether a specific value exists for the given scope (ie it is overriding)
     */
    public function hasOwnValue(string $path, string $scopeName = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, ?int $scopeId = null): bool
    {
        if (ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scopeName) {
            return true; // Default scope always "owns" its values
        }

        // Check if there's a specific value in the database for this scope
        $collection = $this->scopeCollectionFactory->create()
                                                   ->addFieldToFilter('path', $path)
                                                   ->addFieldToFilter('scope', $scopeName)
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
