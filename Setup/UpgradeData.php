<?php

/**
 * Update data for update
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Richard Perdaan <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v3.0.0
 */

namespace MyParcelBE\Magento\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;

/**
 * Upgrade Data script
 * @codeCoverageIgnore
 */
class UpgradeData implements UpgradeDataInterface
{
    /**
     * Upgrades data for a module
     *
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface   $context
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '3.0.0', '<=')) {
            $connection = $setup->getConnection();
            $table    = $setup->getTable('core_config_data');

            if ($connection->isTableExists($table) == true) {

                // Move shipping_methods to myparcelbe_magento_general
                $selectShippingMethodSettings = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelbe_magento_checkout/general/shipping_methods"'
                );

                $shippingMethodData = $connection->fetchAll($selectShippingMethodSettings) ?? [];
                foreach ($shippingMethodData as $value) {
                    $fullPath = 'myparcelbe_magento_general/shipping_methods/methods';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move default_delivery_title to general settings
                $selectDefaultDeliveryTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelbe_magento_checkout/delivery/standard_delivery_title"'
                );

                $defaultDeliveryTitle = $connection->fetchAll($selectDefaultDeliveryTitle) ?? [];
                foreach ($defaultDeliveryTitle as $value) {
                    $fullPath = 'myparcelbe_magento_general/delivery_titles/standard_delivery_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move delivery_title to general settings
                $selectDeliveryTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelbe_magento_checkout/delivery/delivery_title"'
                );

                $deliveryTitle = $connection->fetchAll($selectDeliveryTitle) ?? [];
                foreach ($deliveryTitle as $value) {
                    $fullPath = 'myparcelbe_magento_general/delivery_titles/delivery_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move signature_title to general settings
                $selectSignatureTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelbe_magento_checkout/delivery/delivery_title"'
                );

                $signatureTitle = $connection->fetchAll($selectSignatureTitle) ?? [];
                foreach ($signatureTitle as $value) {
                    $fullPath = 'myparcelbe_magento_general/delivery_titles/signature_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move pickup_title to general settings
                $selectPickupTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelbe_magento_checkout/pickup/title"'
                );

                $pickupTitle = $connection->fetchAll($selectPickupTitle) ?? [];
                foreach ($pickupTitle as $value) {
                    $fullPath = 'myparcelbe_magento_general/delivery_titles/pickup_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move insurance_500_active to carrier settings
                $selectDefaultInsurance = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` LIKE "myparcelbe_magento_standard/options/insurance_500%"'
                );

                $insuranceData = $connection->fetchAll($selectDefaultInsurance) ?? [];
                foreach ($insuranceData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelbe_magento_bpost_settings';
                    $path[1] = 'default_options';

                    $fullPath = implode("/", $path);

                    $bind  = ['path' => $fullPath, 'value' => $value['value']];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move signature_active to carrier settings
                $selectDefaultSignature = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` LIKE "myparcelbe_magento_standard/options/signature%"'
                );

                $signatureData = $connection->fetchAll($selectDefaultSignature) ?? [];
                foreach ($signatureData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelbe_magento_bpost_settings';
                    $path[1] = 'default_options';

                    $fullPath = implode("/", $path);

                    $bind  = ['path' => $fullPath, 'value' => $value['value']];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move myparcelbe_magento_checkout to myparcelbe_magento_bpost_settings
                $selectCheckoutSettings = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` LIKE "myparcelbe_magento_checkout/%"'
                );

                $checkoutData = $connection->fetchAll($selectCheckoutSettings) ?? [];
                foreach ($checkoutData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelbe_magento_bpost_settings';

                    $fullPath = implode("/", $path);

                    $bind  = ['path' => $fullPath, 'value' => $value['value']];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Insert bpost enabled data
                $connection->insert(
                    $table,
                    [
                        'scope'    => 'default',
                        'scope_id' => 0,
                        'path'     => 'myparcelbe_magento_bpost_settings/delivery/active',
                        'value'    => 1
                    ]
                );
            }
        }
    }
}
