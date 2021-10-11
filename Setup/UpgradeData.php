<?php

/**
 * Update data for update
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Richard Perdaan <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v3.0.0
 */

namespace MyParcelNL\Magento\Setup;

use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;

/**
 * Upgrade Data script
 * @codeCoverageIgnore
 */
class UpgradeData implements UpgradeDataInterface
{

    const groupName = 'MyParcel Options';

    /**
     * Category setup factory
     *
     * @var CategorySetupFactory
     */
    private $categorySetupFactory;

    /**
     * EAV setup factory
     *
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * Init
     *
     * @param \Magento\Catalog\Setup\CategorySetupFactory $categorySetupFactory
     * @param EavSetupFactory                             $eavSetupFactory
     */
    public function __construct(\Magento\Catalog\Setup\CategorySetupFactory $categorySetupFactory, EavSetupFactory $eavSetupFactory)
    {
        $this->categorySetupFactory = $categorySetupFactory;
        $this->eavSetupFactory      = $eavSetupFactory;
    }

    /**
     * Upgrades data for a module
     *
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface   $context
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {

       $connection = $setup->getConnection();
       $table      = $setup->getTable('core_config_data');

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

        if (version_compare($context->getVersion(), '2.1.23', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_fit_in_mailbox',
                [
                    'type'                    => 'varchar',
                    'backend'                 => 'Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend',
                    'label'                   => 'Fit in Mailbox',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelNL\Magento\Model\Source\FitInMailboxOptions',
                    'global'                  => \Magento\Catalog\Model\ResourceModel\Eav\Attribute::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => null,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,bundle,grouped',
                    'group'                   => 'General'
                ]
            );
        }

        // Set a new 'MyParcel options' group and place the option 'myparcel_fit_in_mailbox' standard on false by default
        if (version_compare($context->getVersion(), '2.5.0', '<=')) {
            $setup->startSetup();

            // get entity type id so that attribute are only assigned to catalog_product
            $entityTypeId = $eavSetup->getEntityTypeId('catalog_product');
            // Here we have fetched all attribute set as we want attribute group to show under all attribute set
            $attributeSetIds = $eavSetup->getAllAttributeSetIds($entityTypeId);

            foreach ($attributeSetIds as $attributeSetId) {
                $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, self::groupName, 19);
                $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, self::groupName);

                // Add existing attribute to group
                $attributeId = $eavSetup->getAttributeId($entityTypeId, 'myparcel_fit_in_mailbox');
                $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $attributeGroupId, $attributeId, null);
            }
        }

        // Add the option 'Fit in digital stamp'
        if (version_compare($context->getVersion(), '2.5.0', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'group'                   => self::groupName,
                    'type'                    => 'int',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Fit in digital stamp',
                    'input'                   => 'boolean',
                    'class'                   => '',
                    'source'                  => '',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => '0',
                    'searchable'              => true,
                    'filterable'              => true,
                    'comparable'              => true,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );
        }

        // Add the option 'Fit in digital stamp' and 'myparcel_fit_in_mailbox' on default by false
        if (version_compare($context->getVersion(), '3.1.0', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'visible'                 => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false
                ]
            );

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_fit_in_mailbox',
                [
                    'visible'                 => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false
                ]
            );
        }

        // This migration is necessary because the migration for version 3.1.0 was not correct used.
        // The data in the database was not filled in correctly, that was the reason why DPZ and BBP were not visible in the settings.
        if (version_compare($context->getVersion(), '3.1.4', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'group'                   => self::groupName,
                    'type'                    => 'int',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Fit in digital stamp',
                    'input'                   => 'boolean',
                    'class'                   => '',
                    'source'                  => '',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => '0',
                    'searchable'              => true,
                    'filterable'              => true,
                    'comparable'              => true,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_fit_in_mailbox',
                [
                    'group'                   => self::groupName,
                    'type'                    => 'varchar',
                    'backend'                 => 'Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend',
                    'label'                   => 'Fit in Mailbox',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelNL\Magento\Model\Source\FitInMailboxOptions',
                    'global'                  => \Magento\Catalog\Model\ResourceModel\Eav\Attribute::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => null,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => 'simple,configurable,bundle,grouped',
                    'group'                   => 'General'
                ]
            );
        }

        // Add the option 'HS code for products'
        if (version_compare($context->getVersion(), '3.2.0', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_classification',
                [
                    'group'                   => self::groupName,
                    'note'                    => 'HS Codes are used for MyParcel world shipments, you can find the appropriate code on the site of the Dutch Customs',
                    'type'                    => 'int',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'HS code',
                    'input'                   => 'text',
                    'class'                   => '',
                    'source'                  => '',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => '0',
                    'searchable'              => true,
                    'filterable'              => true,
                    'comparable'              => true,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );

            // Enable / Disable checkout with this product.
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_disable_checkout',
                [
                    'group'                   => self::groupName,
                    'note'                    => 'With this option you can disable the delivery options for this product.',
                    'type'                    => 'int',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Disable checkout with this product',
                    'input'                   => 'boolean',
                    'class'                   => '',
                    'source'                  => '',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => 0,
                    'searchable'              => true,
                    'filterable'              => true,
                    'comparable'              => true,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );

            // Set a dropoff delay for this product.
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_dropoff_delay',
                [
                    'group'                   => self::groupName,
                    'note'                    => 'This options allows you to set the number of days it takes you to pick, pack and hand in your parcels at PostNL when ordered before the cutoff time.',
                    'type'                    => 'varchar',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Dropoff-delay',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelNL\Magento\Model\Source\DropOffDelayDays',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => null,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );

            // Move paper type from print to basic settings
            $selectPaperTypeSetting = $connection->select()->from(
                $table,
                ['config_id', 'path', 'value']
            )->where(
                '`path` = "myparcelnl_magento_general/print/paper_type"'
            );

            $paperType = $connection->fetchAll($selectPaperTypeSetting) ?? [];

            foreach ($paperType as $value) {
                $fullPath = 'myparcelnl_magento_general/basic_settings/paper_type';
                $bind     = ['path' => $fullPath, 'value' => $value['value']];
                $where    = 'config_id = ' . $value['config_id'];
                $connection->update($table, $bind, $where);
            }
        }

        if (version_compare($context->getVersion(), '4.0.0', '<=')) {

            $setup->startSetup();
               /** @var EavSetup $eavSetup */
               $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

               // get entity type id so that attribute are only assigned to catalog_product
               $entityTypeId = $eavSetup->getEntityTypeId('catalog_product');
               // Here we have fetched all attribute set as we want attribute group to show under all attribute set
               $attributeSetIds = $eavSetup->getAllAttributeSetIds($entityTypeId);

               foreach ($attributeSetIds as $attributeSetId) {
                   $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, self::groupName, 19);
                   $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, self::groupName);

                   // Add existing attribute to group
                   $attributeId = $eavSetup->getAttributeId($entityTypeId, 'myparcel_fit_in_mailbox');
                   $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $attributeGroupId, $attributeId, null);
               }

            if ($connection->isTableExists($table) == true) {

                // Move shipping_methods to myparcelnl_magento_general
                $selectShippingMethodSettings = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_checkout/general/shipping_methods"'
                );

                $shippingMethodData = $connection->fetchAll($selectShippingMethodSettings) ?? [];
                foreach ($shippingMethodData as $value) {
                    $fullPath = 'myparcelnl_magento_general/shipping_methods/methods';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move default_delivery_title to general settings
                $selectDefaultDeliveryTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_checkout/delivery/standard_delivery_title"'
                );

                $defaultDeliveryTitle = $connection->fetchAll($selectDefaultDeliveryTitle) ?? [];
                foreach ($defaultDeliveryTitle as $value) {
                    $fullPath = 'myparcelnl_magento_general/delivery_titles/standard_delivery_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move delivery_title to general settings
                $selectDeliveryTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_checkout/delivery/delivery_title"'
                );

                $deliveryTitle = $connection->fetchAll($selectDeliveryTitle) ?? [];
                foreach ($deliveryTitle as $value) {
                    $fullPath = 'myparcelnl_magento_general/delivery_titles/delivery_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move signature_title to general settings
                $selectSignatureTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_checkout/delivery/delivery_title"'
                );

                $signatureTitle = $connection->fetchAll($selectSignatureTitle) ?? [];
                foreach ($signatureTitle as $value) {
                    $fullPath = 'myparcelnl_magento_general/delivery_titles/signature_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move pickup_title to general settings
                $selectPickupTitle = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_checkout/pickup/title"'
                );

                $pickupTitle = $connection->fetchAll($selectPickupTitle) ?? [];
                foreach ($pickupTitle as $value) {
                    $fullPath = 'myparcelnl_magento_general/delivery_titles/pickup_title';
                    $bind     = ['path' => $fullPath, 'value' => $value['value']];
                    $where    = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move insurance_500_active to carrier settings
                $selectDefaultInsurance = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` LIKE "myparcelnl_magento_standard/options/insurance_500%"'
                );

                $insuranceData = $connection->fetchAll($selectDefaultInsurance) ?? [];
                foreach ($insuranceData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelnl_magento_postnl_settings';
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
                    '`path` LIKE "myparcelnl_magento_standard/options/signature%"'
                );

                $signatureData = $connection->fetchAll($selectDefaultSignature) ?? [];
                foreach ($signatureData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelnl_magento_postnl_settings';
                    $path[1] = 'default_options';

                    $fullPath = implode("/", $path);

                    $bind  = ['path' => $fullPath, 'value' => $value['value']];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Move myparcelnl_magento_checkout to myparcelnl_magento_postnl_settings
                $selectCheckoutSettings = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` LIKE "myparcelnl_magento_checkout/%"'
                );

                $checkoutData = $connection->fetchAll($selectCheckoutSettings) ?? [];
                foreach ($checkoutData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path);
                    $path[0] = 'myparcelnl_magento_postnl_settings';

                    $fullPath = implode("/", $path);

                    $bind  = ['path' => $fullPath, 'value' => $value['value']];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }

                // Insert postnl enabled data

                $selectDeliveryActive = $connection->select()->from(
                    $table,
                    ['config_id', 'path', 'value']
                )->where(
                    '`path` = "myparcelnl_magento_postnl_settings/delivery/active"'
                );

                $deliveryActive = $connection->fetchAll($selectDeliveryActive) ?? [];

                if (! $deliveryActive){
                    $connection->insert(
                        $table,
                        [
                            'scope'    => 'default',
                            'scope_id' => 0,
                            'path'     => 'myparcelnl_magento_postnl_settings/delivery/active',
                            'value'    => 1
                        ]
                    );
                }
            }
        }

        if (version_compare($context->getVersion(), '4.1.0', '<=')) {
            // Add compatibility for new weight option for large format
            $selectLargeFormatData = $connection->select()->from($table,
                ['config_id', 'path', 'value']
            )->where(
                '`path` = "myparcelnl_magento_postnl_settings/default_options/large_format_active"'
            );

            $largeFormatData = $connection->fetchAll($selectLargeFormatData);

            foreach ($largeFormatData as $value) {
                if ($value['value'] === '1') {
                    $bind  = ['path' => $value['path'], 'value' => 'price'];
                    $where = 'config_id = ' . $value['config_id'];
                    $connection->update($table, $bind, $where);
                }
            }
        }

        if (version_compare($context->getVersion(), '4.2.0', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_age_check',
                [
                    'group'                   => self::groupName,
                    'note'                    => "The age check is intended for parcel shipments for which the recipient must show 18+ by means of a proof of identity. This option can't be combined with morning or evening delivery.",
                    'type'                    => 'varchar',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Age check 18+',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelNL\Magento\Model\Source\AgeCheckOptions',
                    'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'                 => true,
                    'required'                => false,
                    'user_defined'            => true,
                    'default'                 => null,
                    'searchable'              => false,
                    'filterable'              => false,
                    'comparable'              => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => true,
                    'unique'                  => false,
                    'apply_to'                => '',
                ]
            );

            // set new allow_show_delivery_date based on current deliverydays_window
            $selectDeliveryDaysWindow = $connection->select()->from($table,
                ['config_id', 'path', 'value']
            )->where(
                '`path` = "myparcelnl_magento_postnl_settings/general/deliverydays_window"'
            );
            $allowShowDeliveryDatePath = 'myparcelnl_magento_postnl_settings/general/allow_show_delivery_date';

            $connection->delete($table, ['path = ?' => $allowShowDeliveryDatePath]);

            $deliveryDaysWindows = $connection->fetchAll($selectDeliveryDaysWindow);

            foreach ($deliveryDaysWindows as $deliveryDaysWindowOption) {
                $allowValue = '1';
                if ('hide' === $deliveryDaysWindowOption['value']) {
                    $allowValue = '0';
                }
                $bind  = ['path' => $allowShowDeliveryDatePath, 'value' => $allowValue];
                $connection->insert($table, $bind);
            }

        }

        $setup->endSetup();
    }
}
