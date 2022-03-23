<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Settings;

use Magento\Config\Model\ResourceModel\Config;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use Magento\Framework\App\ObjectManager;
use MyParcelNL\Magento\Helper\Data;
use MyParcelNL\Sdk\src\Support\Collection;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierInstabox;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Services\Web\AccountWebService;
use MyParcelNL\Sdk\src\Services\Web\CarrierConfigurationWebService;
use MyParcelNL\Sdk\src\Services\Web\CarrierOptionsWebService;

class CarrierConfigurationImport extends Action
{
    public const CARRIERS_IDS_MAP = [
        CarrierPostNL::NAME   => CarrierPostNL::ID,
        CarrierInstabox::NAME => CarrierInstabox::ID,
    ];

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var \Magento\Framework\App\ObjectManager
     */
    private $objectManager;

    public function __construct()
    {
        $this->objectManager = ObjectManager::getInstance();
        parent::__construct($this->objectManager->get(Context::class));
        $this->resultFactory = $this->objectManager->get(JsonFactory::class);
        $this->apiKey        = $this->objectManager->get(ScopeConfigInterface::class)->getValue(Data::XML_PATH_GENERAL . 'api/key');
        $this->context       = $this->objectManager->get(DbContext::class);
    }

    /**
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function execute()
    {
        $config        = new Config($this->context);
        $path          = Data::XML_PATH_GENERAL . 'account_settings';
        $configuration = $this->fetchConfigurations();
        $config->saveConfig($path, serialize($configuration));

        // Clear configuration cache right after saving the accountsettings, so the modal in the carrier specific
        // configuration view will be showing the updated drop-off point.
        $this->clearCache();
        return $this->resultFactory->create()
            ->setData(['success' => true, 'time' => date('now')]);
    }


    /**
     * @return \MyParcelNL\Sdk\src\Support\Collection
     * @throws \MyParcelNL\Sdk\src\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\src\Exception\ApiException
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     */
    public function fetchConfigurations(): Collection
    {
        $accountService = (new AccountWebService())->setApiKey($this->apiKey);

        $account                     = $accountService->getAccount();
        $shop                        = $account->getShops()->first();
        $shopId                      = $shop->getId();
        $carrierConfigurationService = (new CarrierConfigurationWebService())->setApiKey($this->apiKey);
        $optionConfigurationService  = (new CarrierOptionsWebService())->setApiKey($this->apiKey);
        $carrierConfiguration        = $carrierConfigurationService->getCarrierConfigurations($shopId, true);
        $optionConfiguration         = $optionConfigurationService->getCarrierOptions($shopId);

        return new Collection([
            'shop'                   => $shop,
            'account'                => $account,
            'carrier_options'        => $optionConfiguration,
            'carrier_configurations' => $carrierConfiguration,
        ]);
    }

    private function clearCache(): void
    {
        $cacheTypeList     = $this->objectManager->get(TypeListInterface::class);
        $cacheFrontendPool = $this->objectManager->get(Pool::class);
        $cacheTypeList->cleanType('config');
        foreach ($cacheFrontendPool as $cacheFrontend) {
            $cacheFrontend->getBackend()->clean();
        }
    }
}
