<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use MyParcelNL\Magento\Service\AccountSettings\Importer;
use MyParcelNL\Magento\Service\Config;
use MyParcelNL\Magento\Service\Hash\Fingerprint;
use MyParcelNL\Magento\Service\LogContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imports the account settings again for every stored api key, for an upgrade whose code reads a field
 * that older stored rows do not have.
 *
 * A failure is logged per key and never stops the upgrade: that row stays as it was until the next
 * import. A key that only app/etc/env.php holds has no row here, so the next config save imports it.
 * Gets the importer as a proxy (etc/di.xml), because its graph reaches the admin session.
 */
class ImportAccountSettings
{
    private CollectionFactory $collectionFactory;
    private Importer          $importer;
    private Fingerprint       $fingerprint;
    private LoggerInterface   $logger;

    public function __construct(
        CollectionFactory $collectionFactory,
        Importer          $importer,
        Fingerprint       $fingerprint,
        LoggerInterface   $logger
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->importer          = $importer;
        $this->fingerprint       = $fingerprint;
        $this->logger            = $logger;
    }

    public function run(): void
    {
        foreach ($this->apiKeys() as $apiKey) {
            try {
                $this->importer->importFor($apiKey);
            } catch (Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'MyParcel account settings %s could not be imported during the upgrade; import them in the settings.',
                        substr($this->fingerprint->of($apiKey), 0, Fingerprint::LABEL_LENGTH)
                    ),
                    LogContext::of($e)
                );
            }
        }
    }

    /** @return string[] */
    private function apiKeys(): array
    {
        $keys = [];

        foreach ($this->collectionFactory->create()->addFieldToFilter('path', Config::XML_PATH_API_KEY)->getItems() as $row) {
            $apiKey = trim((string) $row->getData('value'));

            if ('' !== $apiKey) {
                $keys[] = $apiKey;
            }
        }

        return array_values(array_unique($keys));
    }
}
