<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelNL\Magento\Service\Config;

/**
 * Renames sales_shipment_track.myparcel_consignment_id to the code's term, myparcel_shipment_id.
 *
 * Safe to run twice: it renames only while the old column exists and the new one does not. The
 * definition is the one the 0.1.11 step created, so on MySQL 8 the rename changes only metadata.
 */
final class RenameMyParcelShipmentIdColumn
{
    private const OLD_COLUMN = 'myparcel_consignment_id';

    public function run(SchemaSetupInterface $setup): void
    {
        $connection = $setup->getConnection();
        $table      = $setup->getTable('sales_shipment_track');

        if (! $connection->isTableExists($table)
            || ! $connection->tableColumnExists($table, self::OLD_COLUMN)
            || $connection->tableColumnExists($table, Config::FIELD_MYPARCEL_SHIPMENT_ID)) {
            return;
        }

        $connection->changeColumn($table, self::OLD_COLUMN, Config::FIELD_MYPARCEL_SHIPMENT_ID, [
            'type'     => Table::TYPE_INTEGER,
            'nullable' => true,
            'comment'  => 'MyParcel shipment id',
        ]);
    }
}
