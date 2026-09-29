<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;

/**
 * Deletes the stored `mailbox/pickup_mailbox` row of every carrier in Config::CARRIERS_XML_PATH_MAP.
 *
 * The setting was dead end to end: its value was written onto the package object and never read
 * back, and no admin field ever offered it. The defaults went with Package.php, and these rows are
 * what a merchant could still have from a build that wrote them.
 *
 * Idempotent, and it removes nothing a reader would miss.
 */
class RemovePickupMailboxRows
{
    private const FIELD = 'mailbox/pickup_mailbox';

    private CollectionFactory $collectionFactory;
    private WriterInterface   $configWriter;

    public function __construct(CollectionFactory $collectionFactory, WriterInterface $configWriter)
    {
        $this->collectionFactory = $collectionFactory;
        $this->configWriter      = $configWriter;
    }

    public function run(): void
    {
        $paths = array_map(static function (string $carrierPath): string {
            return $carrierPath . self::FIELD;
        }, array_values(Config::CARRIERS_XML_PATH_MAP));

        $items = $this->collectionFactory->create()
            ->addFieldToFilter('path', ['in' => $paths])
            ->getItems();

        foreach ($items as $row) {
            $this->configWriter->delete(
                (string) $row->getData('path'),
                (string) $row->getData('scope'),
                (int) $row->getData('scope_id')
            );
        }
    }
}
