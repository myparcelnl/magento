<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Sdk\Model\Account\CarrierOptions;
use MyParcelNL\Sdk\Services\Web\AccountWebService;
use MyParcelNL\Sdk\Services\Web\CarrierOptionsWebService;
use MyParcelNL\Sdk\Support\Collection;

/**
 * Fetches a MyParcel account's settings from the backoffice and caches them under the api key's
 * fingerprint (see Config::XML_PATH_ACCOUNT_SETTINGS for why that is the key).
 *
 * Shared by the *Import MyParcel Backoffice settings* button and the automatic import on an api key
 * change.
 *
 * Throws whatever the SDK throws: an invalid key or unreachable API must surface, but must not abort a
 * config save, so the observer catches it.
 */
class Importer
{
    private WriterInterface $configWriter;
    private Fingerprint     $fingerprint;

    public function __construct(
        WriterInterface $configWriter,
        Fingerprint     $fingerprint
    ) {
        $this->configWriter = $configWriter;
        $this->fingerprint  = $fingerprint;
    }

    /**
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function importFor(string $apiKey): void
    {
        $this->configWriter->save(
            Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey),
            json_encode($this->createArray($this->fetchConfigurations($apiKey)))
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
