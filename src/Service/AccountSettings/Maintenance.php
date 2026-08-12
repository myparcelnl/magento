<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps the stored account settings rows in step with the api keys that are configured.
 *
 * Two kinds of drift, both settled by the same comparison — hence one pass, not two: rows predating
 * the fingerprinted path still carry the plaintext api key and get rewritten under its fingerprint,
 * and rows for keys configured nowhere get deleted.
 *
 * Invariants:
 *
 *  - Idempotent, which is what makes it safe to call from ordinary admin flows rather than from a
 *    version-gated upgrade script.
 *  - Never deletes when the live key set is empty: that means a fresh install or a failed read, and
 *    neither justifies discarding settings.
 *  - The live key set comes from core_config_data *and* ScopeConfigInterface — the first reflects
 *    writes made earlier in the same request, the second also covers a key locked into env.php.
 *  - Nothing logged may contain an api key.
 */
class Maintenance
{
    private const CONFIG_CACHE_TYPE = 'config';

    /**
     * Characters of a fingerprint used as a log label. Enough to correlate lines with a row without
     * printing a full digest on every one.
     */
    private const LOG_LABEL_LENGTH = 12;

    private CollectionFactory     $collectionFactory;
    private WriterInterface       $configWriter;
    private TypeListInterface     $cacheTypeList;
    private ScopeConfigInterface  $scopeConfig;
    private StoreManagerInterface $storeManager;
    private Fingerprint           $fingerprint;
    private LoggerInterface       $logger;

    public function __construct(
        CollectionFactory     $collectionFactory,
        WriterInterface       $configWriter,
        TypeListInterface     $cacheTypeList,
        ScopeConfigInterface  $scopeConfig,
        StoreManagerInterface $storeManager,
        Fingerprint           $fingerprint,
        LoggerInterface       $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
        $this->cacheTypeList     = $cacheTypeList;
        $this->scopeConfig       = $scopeConfig;
        $this->storeManager      = $storeManager;
        $this->fingerprint       = $fingerprint;
        $this->logger            = $logger;
    }

    /**
     * Migrates legacy plaintext-suffixed rows and deletes orphans. Safe to call repeatedly.
     */
    public function reconcile(): void
    {
        $liveKeys = $this->liveApiKeys();

        if (! $liveKeys) {
            return;
        }

        $liveFingerprints = [];
        foreach ($liveKeys as $apiKey) {
            $liveFingerprints[$this->fingerprint->of($apiKey)] = true;
        }

        $changed = false;

        foreach ($this->accountSettingsRows() as $row) {
            $path    = (string) $row->getData('path');
            $suffix  = substr($path, strlen(Config::XML_PATH_ACCOUNT_SETTINGS));
            $scope   = (string) $row->getData('scope');
            $scopeId = (int) $row->getData('scope_id');

            if ('' === $suffix || isset($liveFingerprints[$suffix])) {
                continue;
            }

            if (in_array($suffix, $liveKeys, true)) {
                $this->migrate($path, $suffix, (string) $row->getData('value'), $scope, $scopeId);
                $changed = true;
                continue;
            }

            $this->configWriter->delete($path, $scope, $scopeId);
            $this->logger->notice(
                sprintf(
                    'Deleted orphaned MyParcel account settings %s: its api key is no longer configured.',
                    $this->logLabel($suffix)
                )
            );
            $changed = true;
        }

        if ($changed) {
            $this->cacheTypeList->cleanType(self::CONFIG_CACHE_TYPE);
        }
    }

    /**
     * Rewrites a legacy plaintext-suffixed row under the api key's fingerprint.
     *
     * Writes before deleting: a failed delete merely duplicates the row, which the next pass resolves,
     * where the reverse order would lose the settings outright.
     */
    private function migrate(string $legacyPath, string $apiKey, string $value, string $scope, int $scopeId): void
    {
        $fingerprint = $this->fingerprint->of($apiKey);

        $this->configWriter->save(Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint, $value, $scope, $scopeId);
        $this->configWriter->delete($legacyPath, $scope, $scopeId);

        $this->logger->notice(
            sprintf(
                'Moved MyParcel account settings to fingerprinted config path %s.',
                substr($fingerprint, 0, self::LOG_LABEL_LENGTH)
            )
        );
    }

    /**
     * A short, non-sensitive label for a row suffix.
     *
     * An existing fingerprint is safe to show and correlates with the row; a plaintext api key gets
     * fingerprinted first, so the credential never reaches the log.
     */
    private function logLabel(string $suffix): string
    {
        $safe = $this->fingerprint->isFingerprint($suffix) ? $suffix : $this->fingerprint->of($suffix);

        return substr($safe, 0, self::LOG_LABEL_LENGTH);
    }

    /**
     * Every api key currently configured anywhere, de-duplicated.
     *
     * @return string[]
     */
    private function liveApiKeys(): array
    {
        $keys = [];

        foreach ($this->configRows(Config::XML_PATH_API_KEY) as $row) {
            $this->collectKey($keys, (string) $row->getData('value'));
        }

        foreach ($this->scopeCoordinates() as [$scope, $scopeId]) {
            $value = ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $scope
                ? $this->scopeConfig->getValue(Config::XML_PATH_API_KEY)
                : $this->scopeConfig->getValue(Config::XML_PATH_API_KEY, $scope, $scopeId);

            $this->collectKey($keys, (string) $value);
        }

        return array_keys($keys);
    }

    /**
     * @param array<string, true> $keys
     */
    private function collectKey(array &$keys, string $value): void
    {
        $value = trim($value);

        if ('' !== $value) {
            $keys[$value] = true;
        }
    }

    /**
     * Default, plus every store and every website that has a store. Websites are derived from the
     * stores, so a website holding no stores is skipped — it cannot carry an order, so its api key is
     * irrelevant here.
     *
     * A store-manager failure is logged, not propagated: the config rows read above are still a usable
     * live key set, and aborting would leave the drift unresolved.
     *
     * @return array<int, array{0: string, 1: int}>
     */
    private function scopeCoordinates(): array
    {
        $coordinates = [[ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0]];
        $websiteIds  = [];

        try {
            foreach ($this->storeManager->getStores(false) as $store) {
                $coordinates[]                            = [ScopeInterface::SCOPE_STORES, (int) $store->getId()];
                $websiteIds[(int) $store->getWebsiteId()] = true;
            }
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not enumerate stores while reconciling MyParcel account settings: ' . $e->getMessage()
            );
        }

        foreach (array_keys($websiteIds) as $websiteId) {
            $coordinates[] = [ScopeInterface::SCOPE_WEBSITES, $websiteId];
        }

        return $coordinates;
    }

    /**
     * All account settings rows, across every scope.
     *
     * SQL treats the underscores in the prefix as single-character wildcards, so the LIKE only narrows
     * the query and the prefix is re-checked in PHP.
     *
     * @return \Magento\Framework\DataObject[]
     */
    private function accountSettingsRows(): array
    {
        $rows = [];

        foreach ($this->configRows(['like' => Config::XML_PATH_ACCOUNT_SETTINGS . '%']) as $row) {
            if (0 === strpos((string) $row->getData('path'), Config::XML_PATH_ACCOUNT_SETTINGS)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  string|array<string, mixed> $pathCondition
     * @return \Magento\Framework\DataObject[]
     */
    private function configRows($pathCondition): array
    {
        return $this->collectionFactory->create()
            ->addFieldToFilter('path', $pathCondition)
            ->getItems();
    }
}
