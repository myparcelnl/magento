<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use MyParcelNL\Magento\Service\Config;

/**
 * Deletes the stored `mailbox/pickup_mailbox` row of every carrier.
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
        $items = $this->collectionFactory->create()
            // `_` is a LIKE wildcard, so escape it; `%` stays the carrier wildcard.
            ->addFieldToFilter('path', ['like' => str_replace('_', '\\_', Config::carrierPath('%') . self::FIELD)])
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
