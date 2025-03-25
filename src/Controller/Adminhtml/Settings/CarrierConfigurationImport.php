<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\JsonFactory;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Sdk\Model\Account\CarrierConfiguration;
use MyParcelNL\Sdk\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\Services\Web\AccountWebService;
use MyParcelNL\Sdk\Services\Web\CarrierConfigurationWebService;
use MyParcelNL\Sdk\Services\Web\CarrierOptionsWebService;
use MyParcelNL\Sdk\Support\Collection;

class CarrierConfigurationImport extends Action
{
    private string $apiKey;
    private Pool   $pool;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var mixed
     */
    private                 $typeListInterface;
    private WriterInterface $configWriter;

    /**
     * @param \Magento\Framework\Controller\Result\JsonFactory   $resultFactory
     * @param \Magento\Backend\App\Action\Context                $context
     * @param \Magento\Framework\Model\ResourceModel\Db\Context  $dbContext
     * @param \Magento\Framework\App\Cache\TypeListInterface     $typeListInterface
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param \Magento\Framework\App\Cache\Frontend\Pool         $pool
     */
    public function __construct(
        Context              $context,
        WriterInterface      $configWriter,
        ScopeConfigInterface $config,
        JsonFactory          $resultFactory,
        TypeListInterface    $typeListInterface,
        Pool                 $pool
    )
    {
        parent::__construct($context);
        $params  = $this->_request->getParams();
        $scope   = $params['scope'] ?? ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeId = $params['scopeId'] ?? 0;

        // Let’s save the carrier configuration settings per api key, so it can be retrieved per api key as well.
        $this->apiKey = $config->getValue(Config::XML_PATH_GENERAL . 'api/key', $scope, $scopeId);

        $this->configWriter      = $configWriter;
        $this->resultFactory     = $resultFactory;
        $this->typeListInterface = $typeListInterface;
        $this->pool              = $pool;
    }

    /**
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function execute()
    {
        $configuration = $this->fetchConfigurations();
        $this->configWriter->save(
            Config::XML_PATH_GENERAL . "account_settings_$this->apiKey",
            json_encode($this->createArray($configuration))
        );

        // Clear configuration cache right after saving the account settings, so the modal in the carrier specific
        // configuration view will be showing the updated drop-off point.
        $this->clearCache();

        return $this->resultFactory->create()
                                   ->setData([
                                                 'success' => true,
                                                 'time'    => date('Y-m-d H:i:s'),
                                             ])
        ;
    }

    /**
     * @return \MyParcelNL\Sdk\Support\Collection
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function fetchConfigurations(): Collection
    {
        $accountService = (new AccountWebService())->setApiKey($this->apiKey);

        $account                     = $accountService->getAccount();
        $shop                        = $account->getShops()
                                               ->first()
        ;
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

    /**
     * @return \MyParcelNL\Sdk\Support\Collection|null
     * @throws \Exception
     */
    public static function getAccountSettings(string $apiKey): ?Collection
    {
        $objectManager   = ObjectManager::getInstance();
        $accountSettings = $objectManager->get(ScopeConfigInterface::class)
                                         ->getValue(Config::XML_PATH_GENERAL . "account_settings_$apiKey")
        ;

        if (!$accountSettings) {
            return null;
        }

        return new Collection(json_decode($accountSettings, true));
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

    /**
     * @param \MyParcelNL\Sdk\Support\Collection $settings
     *
     * @return array
     * @TODO sdk#326 remove this entire function and replace with toArray
     */
    private function createArray(Collection $settings): array
    {
        /** @var \MyParcelNL\Sdk\Model\Account\Shop $shop */
        $shop = $settings->get('shop');
        /** @var \MyParcelNL\Sdk\Model\Account\Account $account */
        $account = $settings->get('account');
        /** @var \MyParcelNL\Sdk\Model\Account\CarrierOptions[]|Collection $carrierOptions */
        $carrierOptions = $settings->get('carrier_options');
        /** @var \MyParcelNL\Sdk\Model\Account\CarrierConfiguration[]|Collection $carrierConfigurations */
        $carrierConfigurations = $settings->get('carrier_configurations');

        return [
            'shop'                   => [
                'id'   => $shop->getId(),
                'name' => $shop->getName(),
            ],
            'account'                => $account->toArray(),
            'carrier_options'        => array_map(static function (CarrierOptions $carrierOptions) {
                $carrier = $carrierOptions->getCarrier();
                return [
                    'carrier'  => [
                        'human' => $carrier->getHuman(),
                        'id'    => $carrier->getId(),
                        'name'  => $carrier->getName(),
                    ],
                    'enabled'  => $carrierOptions->isEnabled(),
                    'label'    => $carrierOptions->getLabel(),
                    'optional' => $carrierOptions->isOptional(),
                ];
            }, $carrierOptions->all()),
            'carrier_configurations' => array_map(static function (CarrierConfiguration $carrierConfiguration) {
                $defaultDropOffPoint = $carrierConfiguration->getDefaultDropOffPoint();
                $carrier             = $carrierConfiguration->getCarrier();
                return [
                    'carrier_id'                        => $carrier->getId(),
                    'default_drop_off_point'            => $defaultDropOffPoint ? [
                        'box_number'        => $defaultDropOffPoint->getBoxNumber(),
                        'cc'                => $defaultDropOffPoint->getCc(),
                        'city'              => $defaultDropOffPoint->getCity(),
                        'location_code'     => $defaultDropOffPoint->getLocationCode(),
                        'location_name'     => $defaultDropOffPoint->getLocationName(),
                        'number'            => $defaultDropOffPoint->getNumber(),
                        'number_suffix'     => $defaultDropOffPoint->getNumberSuffix(),
                        'postal_code'       => $defaultDropOffPoint->getPostalCode(),
                        'region'            => $defaultDropOffPoint->getRegion(),
                        'retail_network_id' => $defaultDropOffPoint->getRetailNetworkId(),
                        'state'             => $defaultDropOffPoint->getState(),
                        'street'            => $defaultDropOffPoint->getStreet(),
                    ] : null,
                    'default_drop_off_point_identifier' => $carrierConfiguration->getDefaultDropOffPointIdentifier(),
                ];
            }, $carrierConfigurations->all()),
        ];
    }
}
