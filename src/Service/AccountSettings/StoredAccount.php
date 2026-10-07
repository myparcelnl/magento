<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Client\Generated\IamApi\Model\Feature;
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
    private ShipmentApiProvider   $apiProvider;
    private StoreManagerInterface $storeManager;

    /** @var array<string, AccountSettings|null> */
    private array $settings = [];

    /** @var array<string, Proposition|null> */
    private array $propositions = [];

    public function __construct(ShipmentApiProvider $apiProvider, StoreManagerInterface $storeManager)
    {
        $this->apiProvider  = $apiProvider;
        $this->storeManager = $storeManager;
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

    /**
     * Whether the account has order v1, which exports entire orders (PPS). An account on order v2 only,
     * a store without an account, and a row imported before features were stored all export shipments.
     */
    public function hasOrderV1ForStore(?int $storeId): bool
    {
        $apiKey   = null === $storeId ? null : $this->apiKeyFor($storeId);
        $features = null === $apiKey ? null : $this->featuresForApiKey($apiKey);

        return in_array(Feature::LEGACY_ORDER_MANAGEMENT, $features ?? [], true);
    }

    /** @return string[]|null null when the stored row has no features yet */
    public function featuresForApiKey(string $apiKey): ?array
    {
        $settings = $this->settingsFor($apiKey);

        return null === $settings ? null : $settings->getFeatures();
    }

    /** @return array<int, bool> store id => hasOrderV1ForStore(), for every store view with an api key */
    public function orderV1ByStore(): array
    {
        $modes = [];

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();

            if (null !== $this->apiKeyFor($storeId)) {
                $modes[$storeId] = $this->hasOrderV1ForStore($storeId);
            }
        }

        return $modes;
    }

    private function accountFor(string $apiKey): ?Account
    {
        $settings = $this->settingsFor($apiKey);

        return null === $settings ? null : $settings->getAccount();
    }

    private function settingsFor(string $apiKey): ?AccountSettings
    {
        if (! array_key_exists($apiKey, $this->settings)) {
            try {
                $this->settings[$apiKey] = new AccountSettings($apiKey);
            } catch (Throwable $e) {
                Logger::alert('Could not read the stored account settings.', LogContext::of($e));

                $this->settings[$apiKey] = null;
            }
        }

        return $this->settings[$apiKey];
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
