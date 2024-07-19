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

namespace MyParcelBE\Magento\Setup;

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
                if ($setup->getConnection()->tableColumnExists($tableQuote, 'delivery_options') === true) {
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
                if ($setup->getConnection()->tableColumnExists($tableSalesOrder, 'delivery_options') === true) {
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
        $setup->endSetup();
    }
}
