<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Moves the stored UPS settings from `myparcelnl_magento_ups_settings/` to the carrier's own name.
 *
 * Every other carrier's path segment is its name, so this makes the path derivable. A row that
 * already exists under the new path wins over the old one. Idempotent.
 */
class RenameUpsSettingsPath
{
    private const OLD_PREFIX = 'myparcelnl_magento_ups_settings/';
    private const NEW_PREFIX = 'myparcelnl_magento_upsstandard_settings/';

    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;

    public function __construct(CollectionFactory $collectionFactory, WriterInterface $configWriter)
    {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
    }

    public function run(): void
    {
        $existing = [];

        foreach ($this->rowsUnder(self::NEW_PREFIX) as $row) {
            $existing[$this->key($row['path'], $row['scope'], $row['scope_id'])] = true;
        }

        foreach ($this->rowsUnder(self::OLD_PREFIX) as $row) {
            $newPath = self::NEW_PREFIX . substr($row['path'], strlen(self::OLD_PREFIX));

            if (! isset($existing[$this->key($newPath, $row['scope'], $row['scope_id'])])) {
                $this->configWriter->save($newPath, $row['value'], $row['scope'], $row['scope_id']);
            }

            $this->configWriter->delete($row['path'], $row['scope'], $row['scope_id']);
        }
    }

    /** @return array<int, array{path: string, value: ?string, scope: string, scope_id: int}> */
    private function rowsUnder(string $prefix): array
    {
        $items = $this->collectionFactory->create()
            ->addFieldToFilter('path', ['like' => $prefix . '%'])
            ->getItems();

        $rows = [];

        foreach ($items as $item) {
            $path = (string) $item->getData('path');

            // SQL reads the underscores in the prefix as single-character wildcards, so check again.
            if (0 !== strpos($path, $prefix)) {
                continue;
            }

            $value  = $item->getData('value');
            $rows[] = [
                'path'     => $path,
                'value'    => null === $value ? null : (string) $value,
                'scope'    => (string) $item->getData('scope'),
                'scope_id' => (int) $item->getData('scope_id'),
            ];
        }

        return $rows;
    }

    private function key(string $path, string $scope, int $scopeId): string
    {
        return "$scope|$scopeId|$path";
    }
}
