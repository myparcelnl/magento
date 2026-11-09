<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Model\Settings\AccountSettings;
use MyParcelNL\Magento\Model\Settings\Proposition;
use MyParcelNL\Magento\Service\Export\ShipmentApiProvider;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Sdk\Client\Generated\IamApi\Model\Feature;
use Throwable;

/**
 * Reads what the stored settings of the account behind an api key say about it. Makes no API call.
 *
 * Null when the settings are not imported or unreadable, or when the proposition is not listed.
 * Only hasOrderV1ForExport() throws: the order grid asks per row and must not fail.
 * Memoised per api key, null included, so a broken row logs once and not once per grid row.
 */
class StoredAccount
{
    public const ERROR_FEATURES_UNKNOWN = 'The MyParcel account settings of this store are not imported, so it is not known whether its orders export as orders or as shipments. Open the MyParcel settings and click Import.';

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
            $this->propositions[$apiKey] = $this->propositionOf($this->settingsFor($apiKey));
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
        $apiKey = $this->apiKeyFor($storeId);

        return null === $apiKey ? null : $this->propositionForApiKey($apiKey);
    }

    public function homeCountryForStore(?int $storeId): string
    {
        return ($this->propositionForStore($storeId) ?? Proposition::default())->getCountryCode();
    }

    /**
     * Check whether the account has order v1, which exports entire orders.
     * A row without features answers false as a guess: an export uses hasOrderV1ForExport().
     * Do not use general_settings.order_mode: that flag is also true on order v2.
     */
    public function hasOrderV1ForStore(?int $storeId): bool
    {
        $apiKey = $this->apiKeyFor($storeId);

        return null !== $apiKey
            && in_array(Feature::LEGACY_ORDER_MANAGEMENT, $this->featuresForApiKey($apiKey) ?? [], true);
    }

    /**
     * Check for order v1 like hasOrderV1ForStore(), but refuse a row without features instead of guessing.
     *
     * @throws LocalizedException when the store's account has no stored features
     */
    public function hasOrderV1ForExport(?int $storeId): bool
    {
        $apiKey = $this->apiKeyFor($storeId);

        if (null !== $apiKey && null === $this->featuresForApiKey($apiKey)) {
            throw new LocalizedException(__(self::ERROR_FEATURES_UNKNOWN));
        }

        return $this->hasOrderV1ForStore($storeId);
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

    private function propositionOf(?AccountSettings $settings): ?Proposition
    {
        $account = null === $settings ? null : $settings->getAccount();

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

    private function apiKeyFor(?int $storeId): ?string
    {
        if (null === $storeId) {
            return null;
        }

        try {
            return $this->apiProvider->apiKeyForStoreOrNull($storeId);
        } catch (Throwable $e) {
            Logger::alert(sprintf('Could not read the api key of store %d.', $storeId), LogContext::of($e));

            return null;
        }
    }
}
