<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
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
 * One row from three sources: the account web service, one unfiltered contract definitions call
 * and whoami. Throws what the SDK throws: an invalid key must surface, so the observer catches it
 * to keep a config save from failing.
 */
class Importer
{
    private WriterInterface      $configWriter;
    private ScopeConfigInterface $scopeConfig;
    private Fingerprint          $fingerprint;
    private LoggerInterface      $logger;
    private Client               $client;
    private UserAgent            $userAgent;
    private AccountFeatures      $accountFeatures;

    public function __construct(
        WriterInterface      $configWriter,
        ScopeConfigInterface $scopeConfig,
        Fingerprint          $fingerprint,
        LoggerInterface      $logger,
        Client               $client,
        UserAgent            $userAgent,
        AccountFeatures      $accountFeatures
    ) {
        $this->configWriter    = $configWriter;
        $this->scopeConfig     = $scopeConfig;
        $this->fingerprint     = $fingerprint;
        $this->logger          = $logger;
        $this->client          = $client;
        $this->userAgent       = $userAgent;
        $this->accountFeatures = $accountFeatures;
    }

    /**
     * Check whether this account's settings are cached in full.
     * A row without features counts as missing, because the export reads them.
     */
    public function hasSettingsFor(string $apiKey): bool
    {
        return null !== self::featuresOf($this->scopeConfig->getValue(
            Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey)
        ));
    }

    /**
     * Fetch the account and replace the stored row. Always costs an API call: to heal a missing row
     * only, check hasSettingsFor() first.
     *
     * @return bool whether the stored row changed
     *
     * @throws \MyParcelNL\Sdk\Exception\ApiException
     * @throws \MyParcelNL\Sdk\Exception\AccountNotActiveException
     * @throws \MyParcelNL\Sdk\Exception\MissingFieldException
     */
    public function importFor(string $apiKey): bool
    {
        $fingerprint = $this->fingerprint->of($apiKey);
        $path        = Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint;
        $before      = $this->scopeConfig->getValue($path);
        $row         = (string) json_encode(
            $this->createArray($this->fetchConfigurations($apiKey), self::featuresOf($before))
        );

        $this->configWriter->save($path, $row);

        // Pairs with the deletion notices in Maintenance, so the log reads as a history of which
        // account's settings were written and removed when.
        $this->logger->notice(
            sprintf(
                'Imported MyParcel account settings %s.',
                substr($fingerprint, 0, Fingerprint::LABEL_LENGTH)
            )
        );

        return $before !== $row;
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
                'features'             => $this->fetchFeatures($apiKey),
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
     * Fetch the account features, or null when whoami fails.
     * On null, createArray() keeps the stored features, so a transient failure does not change the export.
     *
     * @return string[]|null
     */
    private function fetchFeatures(string $apiKey): ?array
    {
        try {
            return $this->accountFeatures->forApiKey($apiKey);
        } catch (Throwable $e) {
            Logger::warning(
                'The account features could not be fetched. The stored features stay until the next import; an account without any cannot export until then.',
                LogContext::of($e)
            );

            return null;
        }
    }

    /**
     * @param mixed $storedRow the raw config value
     *
     * @return string[]|null
     */
    private static function featuresOf($storedRow): ?array
    {
        $decoded = is_string($storedRow) ? json_decode($storedRow, true) : null;

        return is_array($decoded) ? AccountSettings::featuresIn($decoded) : null;
    }

    /**
     * @param \MyParcelNL\Sdk\Support\Collection $settings
     * @param string[]|null                      $storedFeatures of the replaced row, kept when whoami failed
     *
     * @return array
     */
    private function createArray(Collection $settings, ?array $storedFeatures = null): array
    {
        /** @var \MyParcelNL\Sdk\Model\Account\Shop $shop */
        $shop = $settings->get('shop');
        /** @var \MyParcelNL\Sdk\Model\Account\Account $account */
        $account = $settings->get('account');

        $row = [
            'shop'                 => [
                'id'   => $shop->getId(),
                'name' => $shop->getName(),
            ],
            'account'              => $account->toArray(),
            // Stored verbatim: a generated model is an allow-list and would drop the insurance
            // bounds the settings screen reads.
            'contract_definitions' => $settings->get('contract_definitions'),
        ];

        $features = $settings->get('features') ?? $storedFeatures;

        if (null !== $features) {
            $row['features'] = $features;
        }

        return $row;
    }
}
