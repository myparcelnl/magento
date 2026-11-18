<?php
/**
 * Update schema for install and update
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Setup;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UpgradeSchemaInterface;

class UpgradeSchema implements UpgradeSchemaInterface
{
    public function upgrade(
        SchemaSetupInterface $setup,
        ModuleContextInterface $context
    ) {
        $setup->startSetup();

        if (version_compare($context->getVersion(), '0.1.11') < 0) {
            // Add column to track table
            $tableName = $setup->getTable('sales_shipment_track');
            // Check if the table already exists
            if ($setup->getConnection()->isTableExists($tableName) == true) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'myparcel_consignment_id',
                    [
                        'type'    => Table::TYPE_INTEGER,
                        'comment' => 'MyParcel id',
                    ]
                );
                $setup->getConnection()->addColumn(
                    $tableName,
                    'myparcel_status',
                    [
                        'type'    => Table::TYPE_INTEGER,
                        'comment' => 'MyParcel status',
                    ]
                );
            }

            // Add status column to show in order grid
            $tableName = $setup->getTable('sales_order');
            if ($setup->getConnection()->isTableExists($tableName) == true) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'track_status',
                    [
                        'type'    => Table::TYPE_TEXT,
                        'comment' => 'Status of MyParcel consignment'
                    ]
                );
                $setup->getConnection()->addColumn(
                    $tableName,
                    'track_number',
                    [
                        'type'    => Table::TYPE_TEXT,
                        'comment' => 'Track number of MyParcel consignment'
                    ]
                );
            }

            // Add status column to show in order grid
            $tableName = $setup->getTable('sales_order_grid');
            if ($setup->getConnection()->isTableExists($tableName) == true) {
                $setup->getConnection()->addColumn(
                    $tableName,
                    'track_status',
                    [
                        'type'    => Table::TYPE_TEXT,
                        'comment' => 'Status of MyParcel consignment'
                    ]
                );
                $setup->getConnection()->addColumn(
                    $tableName,
                    'track_number',
                    [
                        'type'    => Table::TYPE_TEXT,
                        'comment' => 'Track number of MyParcel consignment'
                    ]
                );
            }
        }

        if (version_compare($context->getVersion(), '2.1.10', '<=')) {
            $setup->getConnection()->addColumn(
                $setup->getTable('quote'),
                'myparcel_delivery_options',
                [
                    'type'     => Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment'  => 'MyParcel delivery options',
                ]
            );

            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order'),
                'myparcel_delivery_options',
                [
                    'type'     => Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment'  => 'MyParcel delivery options',
                ]
            );

            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order'),
                'drop_off_day',
                [
                    'type'     => Table::TYPE_DATE,
                    'nullable' => true,
                    'comment'  => 'MyParcel drop off day',
                ]
            );
            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order_grid'),
                'drop_off_day',
                [
                    'type'     => Table::TYPE_DATE,
                    'nullable' => true,
                    'comment'  => 'MyParcel drop off day',
                ]
            );
        }
        $tableSalesOrder = $setup->getTable('sales_order');

        if (version_compare($context->getVersion(), '4.0.0', '<=')) {
            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order'),
                'myparcel_carrier',
                [
                    'type'     => Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment'  => 'MyParcel carrier',
                ]
            );
            $setup->getConnection()->addColumn(
                $setup->getTable('sales_order_grid'),
                'myparcel_carrier',
                [
                    'type'     => Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment'  => 'MyParcel carrier',
                ]
            );
            $tableQuote = $setup->getTable('quote');
            if ($setup->getConnection()->isTableExists($tableQuote)) {
                if (
                    true === $setup->getConnection()->tableColumnExists($tableQuote, 'delivery_options')
                    && false === $setup->getConnection()->tableColumnExists($tableQuote, 'myparcel_delivery_options')
                ) {
                    $setup->getConnection()->changeColumn(
                        $tableQuote,
                        'delivery_options',
                        'myparcel_delivery_options',
                        [
                            'type'     => Table::TYPE_TEXT,
                            'nullable' => true,
                            'comment'  => 'MyParcel delivery options',
                        ]
                    );
                }
            }
            if ($setup->getConnection()->isTableExists($tableSalesOrder)) {
                if (
                    true === $setup->getConnection()->tableColumnExists($tableSalesOrder, 'delivery_options')
                    && false === $setup->getConnection()->tableColumnExists($tableSalesOrder, 'myparcel_delivery_options')
                ) {
                    $setup->getConnection()->changeColumn(
                        $tableSalesOrder,
                        'delivery_options',
                        'myparcel_delivery_options',
                        [
                            'type'     => Table::TYPE_TEXT,
                            'nullable' => true,
                            'comment'  => 'MyParcel delivery options',
                        ]
                    );
                }
            }
        }
        if (
            version_compare($context->getVersion(), '4.10.0', '<')
            && false === $setup->getConnection()->tableColumnExists($tableSalesOrder, 'myparcel_uuid')
        ) {
            $setup->getConnection()->addColumn(
                $tableSalesOrder,
                'myparcel_uuid',
                [
                    'type'     => Table::TYPE_TEXT,
                    'nullable' => true,
                    'comment'  => 'MyParcel order uuid as received from the api',
                ]
            );
        }
        if (version_compare($context->getVersion(), '6.0.0', '<')) {
            $tableTrack = $setup->getTable('sales_shipment_track');

            if ($setup->getConnection()->isTableExists($tableTrack)) {
                // Length above 255 makes this a TEXT column: a tokenised portal link must not be
                // truncated, and nothing indexes or filters on it.
                $this->addTextColumnOnce(
                    $setup,
                    $tableTrack,
                    'myparcel_tracktrace_url',
                    'MyParcel consumer portal link as received from the api'
                );
                // A JSON list: every return mail sent for this track adds the return shipment it created.
                $this->addTextColumnOnce(
                    $setup,
                    $tableTrack,
                    'myparcel_return_ids',
                    'MyParcel return shipment ids created by the return mail'
                );
                // carrier_code first: equality; myparcel_status is the IN().
                $this->addIndexOnce($setup, $tableTrack, ['carrier_code', 'myparcel_status']);
            }

            $this->addUuidPrefixIndex($setup, $tableSalesOrder);
        }

        $setup->endSetup();
    }

    /** Add a nullable TEXT(1023) column unless it is already there. */
    private function addTextColumnOnce(
        SchemaSetupInterface $setup,
        string $table,
        string $column,
        string $comment
    ): void
    {
        if ($setup->getConnection()->tableColumnExists($table, $column)) {
            return;
        }

        $setup->getConnection()->addColumn($table, $column, [
            'type'     => Table::TYPE_TEXT,
            'length'   => 1023,
            'nullable' => true,
            'comment'  => $comment,
        ]);
    }

    /**
     * Adds an index unless a column it needs is missing or the index is already there, so upgrade()
     * stays re-runnable and an install that never got the columns does not fail here.
     *
     * @param string[] $columns
     */
    private function addIndexOnce(SchemaSetupInterface $setup, string $table, array $columns): void
    {
        $connection = $setup->getConnection();

        foreach ($columns as $column) {
            if (false === $connection->tableColumnExists($table, $column)) {
                return;
            }
        }

        $name = $setup->getIdxName($table, $columns);

        if (isset($connection->getIndexList($table)[strtoupper($name)])) {
            return;
        }

        $connection->addIndex($table, $name, $columns);
    }

    /** Raw DDL: addIndex() cannot set the prefix length that a TEXT column needs. */
    private function addUuidPrefixIndex(SchemaSetupInterface $setup, string $table): void
    {
        $connection = $setup->getConnection();

        if (
            false === $connection->isTableExists($table)
            || false === $connection->tableColumnExists($table, 'myparcel_uuid')
        ) {
            return;
        }

        $name = $setup->getIdxName($table, ['myparcel_uuid']);

        if (isset($connection->getIndexList($table)[strtoupper($name)])) {
            return;
        }

        $connection->query(sprintf(
            'ALTER TABLE %s ADD INDEX %s (myparcel_uuid(36))',
            $connection->quoteIdentifier($table),
            $connection->quoteIdentifier($name)
        ));
    }
}
