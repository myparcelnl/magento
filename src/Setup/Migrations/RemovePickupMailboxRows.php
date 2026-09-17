<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;

/**
 * Deletes the stored `<carrier>/mailbox/pickup_mailbox` rows.
 *
 * The setting was dead end to end: its value was written onto the package object and never read
 * back, and no admin field ever offered it. The defaults went with Package.php, and these rows are
 * what a merchant could still have from a build that wrote them.
 *
 * Idempotent, and it removes nothing a reader would miss.
 */
class RemovePickupMailboxRows
{
    private const SUFFIX = '/mailbox/pickup_mailbox';

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
            ->addFieldToFilter('path', ['like' => '%' . self::SUFFIX])
            ->getItems();

        foreach ($items as $row) {
            $path = (string) $row->getData('path');

            // The LIKE only narrows the query: SQL reads the underscores as single-character
            // wildcards, so the suffix is checked again here.
            if (self::SUFFIX !== substr($path, -strlen(self::SUFFIX))) {
                continue;
            }

            $this->configWriter->delete($path, (string) $row->getData('scope'), (int) $row->getData('scope_id'));
        }
    }
}
