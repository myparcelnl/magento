<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use MyParcelNL\Magento\Model\Cache\Type\Capabilities as CapabilitiesCache;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Exception\AccountNotActiveException;
use MyParcelNL\Sdk\Exception\ApiException;
use MyParcelNL\Sdk\Exception\MissingFieldException;
use MyParcelNL\Sdk\Exception\ValidationException;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Facade\Logger;
use Throwable;

class CarrierConfigurationImport extends Action
{
    private string $apiKey;
    private Pool   $pool;

    /**
     * @var mixed
     */
    private                            $typeListInterface;
    private Importer                   $importer;
    private AccountSettingsMaintenance $accountSettingsMaintenance;

    /**
     * @param Context                    $context
     * @param ScopeConfigInterface       $config
     * @param JsonFactory                $resultFactory
     * @param TypeListInterface          $typeListInterface
     * @param Pool                       $pool
     * @param Importer                   $importer
     * @param AccountSettingsMaintenance $accountSettingsMaintenance
     */
    public function __construct(
        Context                    $context,
        ScopeConfigInterface       $config,
        JsonFactory                $resultFactory,
        TypeListInterface          $typeListInterface,
        Pool                       $pool,
        Importer                   $importer,
        AccountSettingsMaintenance $accountSettingsMaintenance
    )
    {
        parent::__construct($context);
        $params  = $this->_request->getParams();
        $scope   = $params['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeId = $params['scopeId'] ?? 0;

        $this->apiKey = $config->getValue(Config::XML_PATH_API_KEY, $scope, $scopeId);

        $this->resultFactory              = $resultFactory;
        $this->typeListInterface          = $typeListInterface;
        $this->pool                       = $pool;
        $this->importer                   = $importer;
        $this->accountSettingsMaintenance = $accountSettingsMaintenance;
    }

    /**
     * Answers the button rather than throwing. An invalid key is the common failure here, and a 500
     * tells the admin nothing they can act on.
     */
    public function execute()
    {
        try {
            $this->importer->importFor($this->apiKey);
        } catch (AccountNotActiveException|ApiException|MissingFieldException|ValidationException $e) {
            Logger::warning('Could not import MyParcel account settings.', LogContext::of($e));

            return $this->failure($e->getMessage());
        } catch (Throwable $e) {
            // Anything else is a bug, not a message an admin can act on: a TypeError or a DB error
            // would otherwise put its own internals in the button's response.
            Logger::critical('Unexpected error importing MyParcel account settings.', LogContext::of($e));

            return $this->failure((string) __('An unexpected error occurred. Please check the log.'));
        }

        $this->clearCache();
        // After the flush, because it reads config.
        $this->accountSettingsMaintenance->reconcile();

        return $this->resultFactory->create()
                                   ->setData(
                                       [
                                           'success' => true,
                                           'time'    => date('Y-m-d H:i:s'),
                                       ]
                                   )
        ;
    }

    /** The shape the settings button expects on a failed import. */
    private function failure(string $message): ResultInterface
    {
        return $this->resultFactory->create()->setData(['success' => false, 'message' => $message]);
    }

    private function clearCache(): void
    {
        $cacheFrontendPool = $this->pool;
        $this->typeListInterface->cleanType('config');
        $this->typeListInterface->cleanType(CapabilitiesCache::TYPE_IDENTIFIER);

        foreach ($cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()
                          ->clean()
            ;
        }
    }
}
