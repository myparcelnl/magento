<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
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
 * Three sources, one row: the account and its shop come from the SDK's account web service, the
 * contract definitions from the capabilities client in a single unfiltered call, and the features
 * from whoami.
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
     * Check whether this account's settings are stored in full, so a caller can heal a missing row
     * without an API call on every save. A row without features or contract definitions counts as
     * missing: the export reads both, and the next save completes the row.
     */
    public function hasSettingsFor(string $apiKey): bool
    {
        $stored  = $this->scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey));
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return null !== self::featuresOf($stored)
            && is_array($decoded)
            && array_key_exists('contract_definitions', $decoded);
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
                // A failed fetch keeps the stored list rather than replacing it with nothing.
                'contract_definitions' => $this->fetchContractDefinitions($apiKey) ?? $this->storedContractDefinitions($apiKey),
                'features'             => $this->fetchFeatures($apiKey),
            ]
        );
    }

    /** @return array<int, array<string, mixed>>|null the stored list, or null when none is stored */
    private function storedContractDefinitions(string $apiKey): ?array
    {
        $stored  = $this->scopeConfig->getValue(Config::XML_PATH_ACCOUNT_SETTINGS . $this->fingerprint->of($apiKey));
        $decoded = is_string($stored) ? json_decode($stored, true) : null;
        $items   = is_array($decoded) ? ($decoded['contract_definitions'] ?? null) : null;

        return is_array($items) && [] !== $items ? $items : null;
    }

    /**
     * Fetch the contract definitions of every carrier in one call.
     *
     * @return array<int, array<string, mixed>>|null null when the call failed
     */
    private function fetchContractDefinitions(string $apiKey): ?array
    {
        try {
            $answer = $this->client->sendContractDefinitions($apiKey);
        } catch (Throwable $e) {
            $this->logger->warning(
                'No contract definitions could be fetched; the stored ones are kept, or the screens '
                . 'fall open until the next import.',
                LogContext::of($e)
            );

            return null;
        }

        $definitions = array_values(array_filter($answer, 'is_array'));

        if ([] === $definitions) {
            $this->logger->warning('The account answered no contract definitions; the screens fall open until the next import.');
        }

        return $definitions;
    }

    /**
     * Null when whoami fails: the rest of the row is still worth storing, and createArray() keeps the
     * features stored before, so a transient failure never changes the export.
     *
     * @return string[]|null
     */
    private function fetchFeatures(string $apiKey): ?array
    {
        try {
            return $this->accountFeatures->forApiKey($apiKey);
        } catch (Throwable $e) {
            $this->logger->warning(
                'The account features could not be fetched. The stored features stay until the next import; an account without any exports shipments.',
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
     * @param string[]|null                      $storedFeatures of the row being replaced, kept when whoami answered nothing
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
            'shop'    => [
                'id'   => $shop->getId(),
                'name' => $shop->getName(),
            ],
            'account' => $account->toArray(),
        ];

        // Stored verbatim: a generated model is an allow-list and would drop the insurance bounds
        // the settings screen reads. Left out when unknown, so hasSettingsFor() asks again.
        if (null !== $settings->get('contract_definitions')) {
            $row['contract_definitions'] = $settings->get('contract_definitions');
        }

        $features = $settings->get('features') ?? $storedFeatures;

        if (null !== $features) {
            $row['features'] = $features;
        }

        return $row;
    }
}
