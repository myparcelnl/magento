<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Controller\Adminhtml\Settings;

use Magento\Config\Model\ResourceModel\Config;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\Frontend\Pool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\Model\ResourceModel\Db\Context as DbContext;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Model\Sales\MagentoOrderCollection;
use MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration;
use MyParcelNL\Sdk\src\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\src\Support\Collection;
use MyParcelNL\Sdk\src\Services\Web\AccountWebService;
use MyParcelNL\Sdk\src\Services\Web\CarrierConfigurationWebService;
use MyParcelNL\Sdk\src\Services\Web\CarrierOptionsWebService;

class CarrierConfigurationImport extends Action
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $config;

    /**
     * @var mixed
     */
    private $context;

    /**
     * @var mixed
     */
    private $typeListInterface;

    /**
     * @var Pool
     */
    private $pool;

    /**
     * @param  \Magento\Framework\Controller\Result\JsonFactory   $resultFactory
     * @param  \Magento\Backend\App\Action\Context                $context
     * @param  \Magento\Framework\Model\ResourceModel\Db\Context  $dbContext
     * @param  \Magento\Framework\App\Cache\TypeListInterface     $typeListInterface
     * @param  \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param  \Magento\Framework\App\Cache\Frontend\Pool         $pool
     */
    public function __construct(
        JsonFactory          $resultFactory,
        Context              $context,
        DbContext            $dbContext,
        TypeListInterface    $typeListInterface,
        ScopeConfigInterface $config,
        Pool                 $pool
    ) {
        parent::__construct($context);
        $this->resultFactory     = $resultFactory;
        $this->config            = $config;
        $this->apiKey            = $this->config->getValue(Data::XML_PATH_GENERAL . 'api/key');
        $this->context           = $dbContext;
        $this->typeListInterface = $typeListInterface;
        $this->pool              = $pool;
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
        $config->saveConfig($path, json_encode($this->createArray($configuration)));

        // Clear configuration cache right after saving the account settings, so the modal in the carrier specific
        // configuration view will be showing the updated drop-off point.
        $this->clearCache();

        return $this->resultFactory->create()
            ->setData([
                'success' => true,
                'time'    => date('Y-m-d H:i:s'),
            ]);
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
        $shop                        = $account->getShops()
            ->first();
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
     * @return \MyParcelNL\Sdk\src\Support\Collection|null
     * @throws \Exception
     */
    public static function getAccountSettings(): ?Collection
    {
        $objectManager   = ObjectManager::getInstance();
        $accountSettings = $objectManager->get(ScopeConfigInterface::class)
            ->getValue(Data::XML_PATH_GENERAL . 'account_settings');

        if (! $accountSettings) {
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
                ->clean();
        }
    }

    /**
     * @param  \MyParcelNL\Sdk\src\Support\Collection $settings
     *
     * @return array
     * @TODO sdk#326 remove this entire function and replace with toArray
     */
    private function createArray(Collection $settings): array
    {
        /** @var \MyParcelNL\Sdk\src\Model\Account\Shop $shop */
        $shop = $settings->get('shop');
        /** @var \MyParcelNL\Sdk\src\Model\Account\Account $account */
        $account = $settings->get('account');
        /** @var \MyParcelNL\Sdk\src\Model\Account\CarrierOptions[]|Collection $carrierOptions */
        $carrierOptions = $settings->get('carrier_options');
        /** @var \MyParcelNL\Sdk\src\Model\Account\CarrierConfiguration[]|Collection $carrierConfigurations */
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
