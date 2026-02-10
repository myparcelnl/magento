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
use MyParcelNL\Magento\Service\Settings;


class ConfigChange implements ObserverInterface
{
    private RequestInterface      $request;
    private WriterInterface       $configWriter;
    private TypeListInterface     $cacheTypeList;
    private Pool             $cacheFrontendPool;
    private Settings         $dynamicSettingsConfig;
    private ManagerInterface $messageManager;

    public function __construct(
        RequestInterface  $request,
        WriterInterface   $configWriter,
        TypeListInterface $cacheTypeList,
        Pool              $cacheFrontendPool,
        Settings          $dynamicSettingsConfig,
        ManagerInterface  $messageManager
    )
    {
        $this->request               = $request;
        $this->configWriter          = $configWriter;
        $this->cacheTypeList         = $cacheTypeList;
        $this->cacheFrontendPool     = $cacheFrontendPool;
        $this->dynamicSettingsConfig = $dynamicSettingsConfig;
        $this->messageManager        = $messageManager;
    }

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
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Error saving configuration: %1', $e->getMessage()));
        }

        return $this;
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

        foreach ($this->cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }
}
