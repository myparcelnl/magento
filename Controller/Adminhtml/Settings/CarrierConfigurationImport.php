<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\AccountSettings\Maintenance as AccountSettingsMaintenance;
use MyParcelNL\Magento\Service\Config;

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
     * @param \Magento\Backend\App\Action\Context                $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\Controller\Result\JsonFactory   $resultFactory
     * @param \Magento\Framework\App\Cache\TypeListInterface     $typeListInterface
     * @param \Magento\Framework\App\Cache\Frontend\Pool         $pool
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
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function execute()
    {
        $this->importer->importFor($this->apiKey);

        // Flush right away so the modal in the carrier-specific configuration view shows the updated
        // drop-off point.
        $this->clearCache();

        // An import proves which api key is in use, so it is the natural moment to tidy up. After the
        // flush, because it reads config.
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

    private function clearCache(): void
    {
        $cacheFrontendPool = $this->pool;
        $this->typeListInterface->cleanType('config');

        foreach ($cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()
                          ->clean()
            ;
        }
    }
}
