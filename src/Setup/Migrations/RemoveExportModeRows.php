<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;

/**
 * Deletes the stored `print/export_mode` rows at every scope.
 *
 * Whether an account has order v1 decides the export now, so no reader is left. Idempotent.
 */
class RemoveExportModeRows
{
    private const PATH = Config::XML_PATH_GENERAL . 'print/export_mode';

    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;

    public function __construct(CollectionFactory $collectionFactory, WriterInterface $configWriter)
    {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
    }

    public function run(): void
    {
        $items = $this->collectionFactory->create()
            ->addFieldToFilter('path', self::PATH)
            ->getItems();

        foreach ($items as $row) {
            $this->configWriter->delete(self::PATH, (string) $row->getData('scope'), (int) $row->getData('scope_id'));
        }
    }
}
