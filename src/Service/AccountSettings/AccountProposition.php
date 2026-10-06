<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use Throwable;

/**
 * The proposition of the account behind an api key, read from stored account settings — no API call.
 *
 * Null when the settings were never imported or cannot be read, or when the account names a
 * proposition Proposition does not list; that last one logs a warning. Never throws: the order grid
 * asks per row, so an unusable row must not cost the page.
 *
 * Memoised per api key, because the proposition is the account's: stores sharing a key cost one read,
 * and a null is memoised too, or one broken row logs once per grid row.
 */
class AccountProposition
{
    private ShipmentApiProvider $apiProvider;

    /** @var array<string, Proposition|null> */
    private array $byApiKey = [];

    public function __construct(ShipmentApiProvider $apiProvider)
    {
        $this->apiProvider = $apiProvider;
    }

    public function forApiKey(string $apiKey): ?Proposition
    {
        if (! array_key_exists($apiKey, $this->byApiKey)) {
            $this->byApiKey[$apiKey] = $this->read($apiKey);
        }

        return $this->byApiKey[$apiKey];
    }

    /** The home country, or the default proposition's for an account without a listed one. */
    public function homeCountryForApiKey(string $apiKey): string
    {
        return ($this->forApiKey($apiKey) ?? Proposition::default())->getCountryCode();
    }

    /** For a caller that holds a store: the proposition of the store's api key. */
    public function forStore(?int $storeId): ?Proposition
    {
        $apiKey = null === $storeId ? null : $this->apiKeyFor($storeId);

        return null === $apiKey ? null : $this->forApiKey($apiKey);
    }

    public function homeCountryForStore(?int $storeId): string
    {
        return ($this->forStore($storeId) ?? Proposition::default())->getCountryCode();
    }

    private function apiKeyFor(int $storeId): ?string
    {
        try {
            return $this->apiProvider->apiKeyForStoreOrNull($storeId);
        } catch (Throwable $e) {
            Logger::alert(
                sprintf('Could not establish the account proposition for store %d.', $storeId),
                LogContext::of($e)
            );

            return null;
        }
    }

    private function read(string $apiKey): ?Proposition
    {
        try {
            $account = (new AccountSettings($apiKey))->getAccount();
        } catch (Throwable $e) {
            Logger::alert('Could not read the account proposition from the stored account settings.', LogContext::of($e));

            return null;
        }

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
}
