<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Shipment\Capabilities\Client;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Service\UserAgent;
use MyParcelNL\Sdk\Services\Web\AccountWebService;
use MyParcelNL\Sdk\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches a MyParcel account's settings and caches them under the api key's fingerprint.
 *
 * Two sources, one row: the account and its shop come from the SDK's account web service, the
 * contract definitions from the capabilities client in a single unfiltered call.
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
    private Client               $client;
    private UserAgent            $userAgent;

    public function __construct(
        WriterInterface      $configWriter,
        ScopeConfigInterface $scopeConfig,
        Fingerprint          $fingerprint,
        LoggerInterface      $logger,
        Client               $client,
        UserAgent            $userAgent
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig  = $scopeConfig;
        $this->fingerprint  = $fingerprint;
        $this->logger       = $logger;
        $this->client       = $client;
        $this->userAgent    = $userAgent;
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
     * Fetch the account and replace the stored row. Always costs an API call: to heal a missing row
     * only, check hasSettingsFor() first.
     *
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
        $accountService = (new AccountWebService())->setUserAgents($this->userAgent->map())
                                                   ->setApiKey($apiKey);

        // each api key points to a specific shop in an account, so we can just take the first one.
        $account = $accountService->getAccount();
        $shop    = $account->getShops()
                           ->first()
        ;

        return new Collection(
            [
                'shop'                 => $shop,
                'account'              => $account,
                'contract_definitions' => $this->fetchContractDefinitions($apiKey),
            ]
        );
    }

    /**
     * Fetch the contract definitions of every carrier in one call.
     * A refusal gives an empty list, so the admin screens stay open instead of locked.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchContractDefinitions(string $apiKey): array
    {
        $definitions = [];

        try {
            foreach ($this->client->sendContractDefinitions($apiKey) as $item) {
                if (is_array($item)) {
                    $definitions[] = $item;
                }
            }
        } catch (Throwable $e) {
            Logger::notice('No contract definitions could be fetched', LogContext::of($e));
        }

        if ([] === $definitions) {
            Logger::warning(
                'No contract definitions could be fetched for any carrier; capability-bounded admin '
                . 'screens will fall open until the next import.'
            );
        }

        return $definitions;
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

        return [
            'shop'                 => [
                'id'   => $shop->getId(),
                'name' => $shop->getName(),
            ],
            'account'              => $account->toArray(),
            // Stored verbatim: a generated model is an allow-list and would drop the insurance
            // bounds the settings screen reads.
            'contract_definitions' => $settings->get('contract_definitions'),
        ];
    }
}
