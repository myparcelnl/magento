<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Model\Account\Account;
use Throwable;

/**
 * What the stored settings of the account behind an api key say about it — no API call.
 *
 * An account is null when its settings were never imported or cannot be read; a proposition is also
 * null when the account names one Proposition does not list, which logs a warning. Never throws: the
 * order grid asks per row, so an unusable row must not cost the page.
 *
 * Memoised per api key, because these are the account's: stores sharing a key cost one read, and a
 * null is memoised too, or one broken row logs once per grid row. The store methods only map a store
 * to its key for a caller that holds a store.
 */
class StoredAccount
{
    private ShipmentApiProvider $apiProvider;

    /** @var array<string, Account|null> */
    private array $accounts = [];

    /** @var array<string, Proposition|null> */
    private array $propositions = [];

    public function __construct(ShipmentApiProvider $apiProvider)
    {
        $this->apiProvider = $apiProvider;
    }

    public function propositionForApiKey(string $apiKey): ?Proposition
    {
        if (! array_key_exists($apiKey, $this->propositions)) {
            $this->propositions[$apiKey] = $this->propositionOf($this->accountFor($apiKey));
        }

        return $this->propositions[$apiKey];
    }

    /** The home country, or the default proposition's for an account without a listed one. */
    public function homeCountryForApiKey(string $apiKey): string
    {
        return ($this->propositionForApiKey($apiKey) ?? Proposition::default())->getCountryCode();
    }

    public function propositionForStore(?int $storeId): ?Proposition
    {
        $apiKey = null === $storeId ? null : $this->apiKeyFor($storeId);

        return null === $apiKey ? null : $this->propositionForApiKey($apiKey);
    }

    public function homeCountryForStore(?int $storeId): string
    {
        return ($this->propositionForStore($storeId) ?? Proposition::default())->getCountryCode();
    }

    private function accountFor(string $apiKey): ?Account
    {
        if (! array_key_exists($apiKey, $this->accounts)) {
            try {
                $this->accounts[$apiKey] = (new AccountSettings($apiKey))->getAccount();
            } catch (Throwable $e) {
                Logger::alert('Could not read the stored account settings.', LogContext::of($e));

                $this->accounts[$apiKey] = null;
            }
        }

        return $this->accounts[$apiKey];
    }

    private function propositionOf(?Account $account): ?Proposition
    {
        if (null === $account) {
            return null;
        }

        $proposition = Proposition::forId($account->getPropositionId());

        if (null === $proposition) {
            Logger::warning(sprintf(
                'An account has proposition %d, which this module does not list.',
                $account->getPropositionId()
            ));
        }

        return $proposition;
    }

    private function apiKeyFor(int $storeId): ?string
    {
        try {
            return $this->apiProvider->apiKeyForStoreOrNull($storeId);
        } catch (Throwable $e) {
            Logger::alert(sprintf('Could not read the api key of store %d.', $storeId), LogContext::of($e));

            return null;
        }
    }
}
