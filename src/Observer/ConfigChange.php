<?php

namespace MyParcelNL\Magento\Observer;

use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Model\ScopeInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Settings;
use Throwable;


class ConfigChange implements ObserverInterface
{
    private RequestInterface           $request;
    private WriterInterface            $configWriter;
    private TypeListInterface          $cacheTypeList;
    private Pool                       $cacheFrontendPool;
    private Settings                   $dynamicSettingsConfig;
    private ManagerInterface           $messageManager;
    private ScopeConfigInterface       $scopeConfig;
    private Importer                   $accountSettingsImporter;
    private AccountSettingsMaintenance $accountSettingsMaintenance;

    public function __construct(
        RequestInterface           $request,
        WriterInterface            $configWriter,
        TypeListInterface          $cacheTypeList,
        Pool                       $cacheFrontendPool,
        Settings                   $dynamicSettingsConfig,
        ManagerInterface           $messageManager,
        ScopeConfigInterface       $scopeConfig,
        Importer                   $accountSettingsImporter,
        AccountSettingsMaintenance $accountSettingsMaintenance
    )
    {
        $this->request                    = $request;
        $this->configWriter               = $configWriter;
        $this->cacheTypeList              = $cacheTypeList;
        $this->cacheFrontendPool          = $cacheFrontendPool;
        $this->dynamicSettingsConfig      = $dynamicSettingsConfig;
        $this->messageManager             = $messageManager;
        $this->scopeConfig                = $scopeConfig;
        $this->accountSettingsImporter    = $accountSettingsImporter;
        $this->accountSettingsMaintenance = $accountSettingsMaintenance;
    }

    /**
     * Saves every posted dynamic setting, then brings the stored account settings in step: import
     * for an api key that has none, and reconcile away rows for keys configured nowhere.
     */
    public function execute(EventObserver $observer): self
    {
        $request    = $this->request;
        $scope      = $this->convertScope($request->getParam('scope', ScopeConfigInterface::SCOPE_TYPE_DEFAULT));
        $scopeId    = (int) $request->getParam('scope_id', 0);
        $configData = $request->getParam('config', []);
        $validPaths = $this->dynamicSettingsConfig->getAllFieldPaths();

        try {
            foreach ($configData as $path => $postedParams) {
                if (! in_array($path, $validPaths, true)) {
                    continue;
                }

                $value   = $postedParams['value'] ?? null;
                $inherit = '1' === ($postedParams['inherit'] ?? '');

                // Handle checkbox "use default" - if inherit is set, delete the value for this scope
                if ($scope !== ScopeConfigInterface::SCOPE_TYPE_DEFAULT && $inherit) {
                    $this->configWriter->delete($path, $scope, $scopeId);
                    continue;
                }

                if (is_array($value)) {
                    $value = implode(',', $value);
                }

                if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT) {
                    $this->configWriter->save($path, $value);
                } else {
                    $this->configWriter->save($path, $value, $scope, $scopeId);
                }
            }

            $this->clearConfigCache();

            // After the flush, or a stale cache hands us the pre-save key.
            $apiKey = trim((string) ($this->scopeConfig->getValue(Config::XML_PATH_API_KEY, $scope, $scopeId) ?? ''));

            // Whether the key changed is not worth detecting: an unchanged key already has its row, and
            // a key that does not is exactly the case worth importing — including a brand new one. Note
            // every field is posted on every save, so presence in $configData proves nothing.
            if ('' !== $apiKey && ! $this->accountSettingsImporter->hasSettingsFor($apiKey)) {
                // Before reconcile(), which deletes rows for unconfigured keys.
                $this->importAccountSettings($apiKey);
                $this->clearConfigCache();
            }

            $this->accountSettingsMaintenance->reconcile();
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error saving configuration: %1', $e->getMessage()));
        }

        return $this;
    }

    /**
     * Failure is contained on purpose: an invalid key is no reason to refuse to store it, which would
     * leave the admin unable to save anything at all. Warn, let the save succeed, let them retry with
     * the button.
     */
    private function importAccountSettings(string $apiKey): void
    {
        try {
            $this->accountSettingsImporter->importFor($apiKey);
        } catch (Throwable $e) {
            Logger::warning('Could not import MyParcel account settings after an api key change.', ['exception' => $e]);
            $this->messageManager->addWarningMessage(
                __(
                    'Your API key was saved, but the MyParcel account settings could not be imported: %1. Check the API key, then use the Import MyParcel Backoffice settings button.',
                    $e->getMessage()
                )
            );
        }
    }

    /**
     * Convert scope type string to Magento scope constant.
     *
     * @param string $scopeType
     * @return string
     */
    private function convertScope(string $scopeType): string
    {
        switch ($scopeType) {
            case 'websites':
            case 'website':
                return ScopeInterface::SCOPE_WEBSITES;
            case 'stores':
            case 'store':
                return ScopeInterface::SCOPE_STORES;
            default:
                return ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        }
    }

    /**
     * Clear the configuration cache.
     *
     * @return void
     */
    private function clearConfigCache(): void
    {
        $this->cacheTypeList->cleanType('config');
        $this->cacheTypeList->cleanType(CapabilitiesCache::TYPE_IDENTIFIER);

        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }
}
