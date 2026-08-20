<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service\AccountSettings;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes stored account settings rows whose api key is configured nowhere any more.
 *
 * Invariants:
 *  - Idempotent, so calling it after every config save costs nothing when there is nothing to do.
 *  - Never deletes when the live key set is empty — that means a fresh install or a failed read.
 */
class Maintenance
{
    private const CONFIG_CACHE_TYPE = 'config';

    private CollectionFactory    $collectionFactory;
    private WriterInterface      $configWriter;
    private TypeListInterface    $cacheTypeList;
    private ScopeConfigInterface $scopeConfig;
    private Config               $config;
    private Fingerprint          $fingerprint;
    private LoggerInterface      $logger;

    public function __construct(
        CollectionFactory    $collectionFactory,
        WriterInterface      $configWriter,
        TypeListInterface    $cacheTypeList,
        ScopeConfigInterface $scopeConfig,
        Config               $config,
        Fingerprint          $fingerprint,
        LoggerInterface      $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
        $this->cacheTypeList     = $cacheTypeList;
        $this->scopeConfig       = $scopeConfig;
        $this->config            = $config;
        $this->fingerprint       = $fingerprint;
        $this->logger            = $logger;
    }

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
            $path   = (string) $row->getData('path');
            $suffix = substr($path, strlen(Config::XML_PATH_ACCOUNT_SETTINGS));

            if ('' === $suffix || isset($liveFingerprints[$suffix])) {
                continue;
            }

            $this->configWriter->delete(
                $path,
                (string) $row->getData('scope'),
                (int) $row->getData('scope_id')
            );
            $this->logger->notice(
                sprintf(
                    'Deleted orphaned MyParcel account settings %s: its api key is no longer configured.',
                    substr($suffix, 0, Fingerprint::LABEL_LENGTH)
                )
            );
            $changed = true;
        }

        if ($changed) {
            $this->cacheTypeList->cleanType(self::CONFIG_CACHE_TYPE);
        }
    }

    /**
     * Read twice on purpose: the config rows are a direct database read, so they reflect a write made
     * earlier in this request — reconcile() runs right after one — and the scoped reads additionally
     * catch a key locked into app/etc/env.php, which has no row at all.
     *
     * @return string[]
     */
    private function liveApiKeys(): array
    {
        $keys = [];

        foreach ($this->configRows(Config::XML_PATH_API_KEY) as $row) {
            $this->collectKey($keys, (string) $row->getData('value'));
        }

        try {
            $coordinates = $this->config->getScopeCoordinates();
        } catch (Throwable $e) {
            $this->logger->warning(
                'Could not enumerate scopes while reconciling MyParcel account settings: ' . $e->getMessage()
            );
            return [];
        }

        foreach ($coordinates as [$scope, $scopeId]) {
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
