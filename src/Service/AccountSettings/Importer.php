<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\Services\Web\AccountWebService;
use MyParcelNL\Sdk\Services\Web\CarrierOptionsWebService;
use MyParcelNL\Sdk\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Fetches a MyParcel account's settings and caches them under the api key's fingerprint (see
 * Config::XML_PATH_ACCOUNT_SETTINGS for why that is the key). Shared by the *Import MyParcel Backoffice
 * settings* button and the automatic import on an api key change.
 *
 * Throws whatever the SDK throws: an invalid key must surface, but must not abort a config save, so the
 * observer catches it.
 */
class Importer
{
    private WriterInterface      $configWriter;
    private ScopeConfigInterface $scopeConfig;
    private Fingerprint          $fingerprint;
    private LoggerInterface      $logger;

    public function __construct(
        WriterInterface      $configWriter,
        ScopeConfigInterface $scopeConfig,
        Fingerprint          $fingerprint,
        LoggerInterface      $logger
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig  = $scopeConfig;
        $this->fingerprint  = $fingerprint;
        $this->logger       = $logger;
    }

    /**
     * Whether this account's settings are already cached, so a caller can heal a missing row without
     * paying for an API call on every save.
     */
    public function hasSettingsFor(string $apiKey): bool
    {
        return (bool) $this->scopeConfig->getValue(
            Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey)
        );
    }

    /**
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function importFor(string $apiKey): void
    {
        $fingerprint = $this->fingerprint->of($apiKey);

        $this->configWriter->save(
            Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint,
            json_encode($this->createArray($this->fetchConfigurations($apiKey)))
        );

        // Pairs with the deletion notices in Maintenance, so the log reads as a history of which
        // account's settings were written and removed when.
        $this->logger->notice(
            sprintf(
                'Imported MyParcel account settings %s.',
                substr($fingerprint, 0, Fingerprint::LABEL_LENGTH)
            )
        );
    }

    /**
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    private function fetchConfigurations(string $apiKey): Collection
    {
        $accountService = (new AccountWebService())->setApiKey($apiKey);

        // each api key points to a specific shop in an account, so we can just take the first one.
        $account = $accountService->getAccount();
        $shop    = $account->getShops()
                           ->first()
        ;
        $shopId                     = $shop->getId();
        $optionConfigurationService = (new CarrierOptionsWebService())->setApiKey($apiKey);
        $optionConfiguration        = $optionConfigurationService->getCarrierOptions($shopId);

        return new Collection(
            [
                'shop'            => $shop,
                'account'         => $account,
                'carrier_options' => $optionConfiguration,
            ]
        );
    }

    /**
     * @param \MyParcelNL\Sdk\Support\Collection $settings
     *
     * @return array
     */
    private function createArray(Collection $settings): array
    {
        /** @var \MyParcelNL\Sdk\Model\Account\Shop $shop */
        $shop = $settings->get('shop');
        /** @var \MyParcelNL\Sdk\Model\Account\Account $account */
        $account = $settings->get('account');
        /** @var \MyParcelNL\Sdk\Model\Account\CarrierOptions[]|Collection $carrierOptions */
        $carrierOptions = $settings->get('carrier_options');

        return [
            'shop'            => [
                'id'   => $shop->getId(),
                'name' => $shop->getName(),
            ],
            'account'         => $account->toArray(),
            'carrier_options' => array_map(static function (CarrierOptions $carrierOptions) {
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
                    'type'     => $carrierOptions->getType(),
                ];
            }, $carrierOptions->all()),
        ];
    }
}
