<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use Psr\Log\LoggerInterface;

/**
 * Rewrites account settings rows that still carry a plaintext api key in their config path to the
 * path keyed by the api key's fingerprint (see Config::XML_PATH_ACCOUNT_SETTINGS).
 *
 * Invariants:
 *  - Idempotent — an already-fingerprinted suffix is skipped, so a re-run is a no-op and a
 *    mis-placed version gate cannot corrupt anything.
 *  - Writes the target row at default scope only, whatever scope the legacy row sat at.
 *  - Never overwrites an existing fingerprinted row; that one is at least as fresh.
 *  - Nothing logged may contain an api key.
 */
class FingerprintAccountSettingsPaths
{
    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;
    private Fingerprint       $fingerprint;
    private LoggerInterface   $logger;

    public function __construct(
        CollectionFactory $collectionFactory,
        WriterInterface   $configWriter,
        Fingerprint       $fingerprint,
        LoggerInterface   $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
        $this->fingerprint       = $fingerprint;
        $this->logger            = $logger;
    }

    public function run(): void
    {
        $rows        = $this->accountSettingsRows();
        $targetPaths = $this->existingDefaultScopePaths($rows);

        foreach ($rows as $row) {
            $path   = (string) $row->getData('path');
            $suffix = substr($path, strlen(Config::XML_PATH_ACCOUNT_SETTINGS));

            if ('' === $suffix || $this->fingerprint->isFingerprint($suffix)) {
                continue;
            }

            $fingerprint = $this->fingerprint->of($suffix);
            $targetPath  = Config::XML_PATH_ACCOUNT_SETTINGS . $fingerprint;

            // A target row already there is either a fresh import or a sibling legacy row already
            // handled in this loop. Either way it beats this row's value, so only drop the legacy one.
            if (! isset($targetPaths[$targetPath])) {
                $this->configWriter->save($targetPath, (string) $row->getData('value'));
                $targetPaths[$targetPath] = true;
            }

            $this->configWriter->delete($path, (string) $row->getData('scope'), (int) $row->getData('scope_id'));

            $this->logger->notice(
                sprintf(
                    'Moved MyParcel account settings to fingerprinted config path %s.',
                    substr($fingerprint, 0, Fingerprint::LABEL_LENGTH)
                )
            );
        }
    }

    /**
     * Account settings only live at default scope, so this is the full set of paths run() could
     * already have a target row at.
     *
     * @param  \Magento\Framework\DataObject[] $rows
     * @return array<string, true>
     */
    private function existingDefaultScopePaths(array $rows): array
    {
        $paths = [];

        foreach ($rows as $row) {
            $isDefaultScope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT === $row->getData('scope')
                && 0 === (int) $row->getData('scope_id');

            if ($isDefaultScope) {
                $paths[(string) $row->getData('path')] = true;
            }
        }

        return $paths;
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

        $items = $this->collectionFactory->create()
            ->addFieldToFilter('path', ['like' => Config::XML_PATH_ACCOUNT_SETTINGS . '%'])
            ->getItems();

        foreach ($items as $row) {
            if (0 === strpos((string) $row->getData('path'), Config::XML_PATH_ACCOUNT_SETTINGS)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
