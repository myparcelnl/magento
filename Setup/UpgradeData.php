<?php

declare(strict_types=1);

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

namespace MyParcelBE\Magento\Setup;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Setup\CategorySetupFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use MyParcelBE\Magento\Helper\Data;
use MyParcelBE\Magento\Setup\Migrations\ReplaceDpzRange;
use MyParcelBE\Magento\Setup\Migrations\ReplaceFitInMailbox;
use MyParcelBE\Magento\Setup\Migrations\ReplaceDisableCheckout;
use MyParcelBE\Magento\Model\Source\FitInMailboxOptions;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;

/**
 * Upgrade Data script
 * @codeCoverageIgnore
 */
class UpgradeData implements UpgradeDataInterface
{
    private const GROUP_NAME = 'MyParcel Options';

    private const DEFAULT_ATTRIBUTES = [
        'group'                   => self::GROUP_NAME,
        'type'                    => 'int',
        'backend'                 => '',
        'frontend'                => '',
        'class'                   => '',
        'source'                  => '',
        'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
        'visible'                 => true,
        'required'                => false,
        'user_defined'            => true,
        'searchable'              => false,
        'filterable'              => false,
        'comparable'              => true,
        'visible_on_front'        => false,
        'used_in_product_listing' => true,
        'unique'                  => false,
        'apply_to'                => '',
    ];

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
     * @var \MyParcelBE\Magento\Setup\Migrations\ReplaceFitInMailbox
     */
    private $replaceFitInMailbox;

    /**
     * @var \MyParcelBE\Magento\Setup\Migrations\ReplaceDisableCheckout
     */
    private $replaceDisableCheckout;

    /**
     * @var \MyParcelBE\Magento\Setup\Migrations\ReplaceDpzRange
     */
    private $replaceDpzRange;

    /**
     * @param  \Magento\Catalog\Setup\CategorySetupFactory                 $categorySetupFactory
     * @param  \Magento\Eav\Setup\EavSetupFactory                          $eavSetupFactory
     * @param  \MyParcelBE\Magento\Setup\Migrations\ReplaceFitInMailbox    $replaceFitInMailbox
     * @param  \MyParcelBE\Magento\Setup\Migrations\ReplaceDisableCheckout $replaceDisableCheckout
     * @param  \MyParcelBE\Magento\Setup\Migrations\ReplaceDpzRange        $replaceDpzRange
     */
    public function __construct(
        \Magento\Catalog\Setup\CategorySetupFactory $categorySetupFactory,
        EavSetupFactory $eavSetupFactory,
        ReplaceFitInMailbox $replaceFitInMailbox,
        ReplaceDisableCheckout $replaceDisableCheckout,
        ReplaceDpzRange $replaceDpzRange
    ) {
        $this->categorySetupFactory   = $categorySetupFactory;
        $this->eavSetupFactory        = $eavSetupFactory;
        $this->replaceFitInMailbox    = $replaceFitInMailbox;
        $this->replaceDisableCheckout = $replaceDisableCheckout;
        $this->replaceDpzRange        = $replaceDpzRange;
    }

    /**
     * Upgrades data for a module
     *
     * @param  \Magento\Framework\Setup\ModuleDataSetupInterface $setup
     * @param  \Magento\Framework\Setup\ModuleContextInterface   $context
     * @throws \Exception
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
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                [
                    'type'                    => 'varchar',
                    'backend'                 => 'Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend',
                    'label'                   => 'Fit in Mailbox',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelBE\Magento\Model\Source\FitInMailboxOptions',
                    'global'                  => Attribute::SCOPE_GLOBAL,
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
                $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, self::GROUP_NAME, 19);
                $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, self::GROUP_NAME);

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
                Product::ENTITY,
                'myparcel_digital_stamp',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'label'   => 'Fit in digital stamp',
                        'input'   => 'boolean',
                        'default' => '0',
                    ]
                )
            );
        }

        // Add the option 'Fit in digital stamp' and 'myparcel_fit_in_mailbox' on default by false
        if (version_compare($context->getVersion(), '3.1.0', '<=')) {
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'visible'                 => false,
                    'visible_on_front'        => false,
                    'used_in_product_listing' => false
                ]
            );

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                Product::ENTITY,
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
                Product::ENTITY,
                'myparcel_digital_stamp',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'label'   => 'Fit in digital stamp',
                        'input'   => 'boolean',
                        'default' => '0',
                    ]
                )
            );

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                [
                    'group'                   => self::GROUP_NAME,
                    'type'                    => 'varchar',
                    'backend'                 => ArrayBackend::class,
                    'label'                   => 'Fit in Mailbox',
                    'input'                   => 'input',
                    'class'                   => '',
                    'source'                  => FitInMailboxOptions::class,
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
                Product::ENTITY,
                'myparcel_classification',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'note'    => 'HS Codes are used for MyParcel world shipments, you can find the appropriate code on the site of the Dutch Customs',
                        'label'   => 'HS code',
                        'input'   => 'text',
                        'default' => '0',
                    ]
                )
            );

            // Enable / Disable checkout with this product.
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_disable_checkout',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'note'    => 'With this option you can disable the delivery options for this product.',
                        'label'   => 'Disable checkout with this product',
                        'input'   => 'boolean',
                        'default' => 0,
                    ]
                )
            );

            // Set a dropoff delay for this product.
            $setup->startSetup();

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_dropoff_delay',
                [
                    'group'                   => self::GROUP_NAME,
                    'note'                    => 'This options allows you to set the number of days it takes you to pick, pack and hand in your parcels at PostNL when ordered before the cutoff time.',
                    'type'                    => 'varchar',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Dropoff-delay',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelBE\Magento\Model\Source\DropOffDelayDays',
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
                '`path` = "myparcelbe_magento_general/print/paper_type"'
            );

            $paperType = $connection->fetchAll($selectPaperTypeSetting) ?? [];

            foreach ($paperType as $value) {
                $fullPath = 'myparcelbe_magento_general/basic_settings/paper_type';
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
                   $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, self::GROUP_NAME, 19);
                   $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, self::GROUP_NAME);

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
                    $path    = explode("/", $path ?? '');
                    $path[0] = 'myparcelbe_magento_postnl_settings';
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
                    $path    = explode("/", $path ?? '');
                    $path[0] = 'myparcelbe_magento_postnl_settings';
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
                    '`path` LIKE "myparcelbe_magento_checkout/%"'
                );

                $checkoutData = $connection->fetchAll($selectCheckoutSettings) ?? [];
                foreach ($checkoutData as $value) {
                    $path    = $value['path'];
                    $path    = explode("/", $path ?? '');
                    $path[0] = 'myparcelbe_magento_postnl_settings';

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
                    '`path` = "myparcelbe_magento_postnl_settings/delivery/active"'
                );

                $deliveryActive = $connection->fetchAll($selectDeliveryActive) ?? [];

                if (! $deliveryActive){
                    $connection->insert(
                        $table,
                        [
                            'scope'    => 'default',
                            'scope_id' => 0,
                            'path'     => 'myparcelbe_magento_postnl_settings/delivery/active',
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
                '`path` = "myparcelbe_magento_postnl_settings/default_options/large_format_active"'
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
                Product::ENTITY,
                'myparcel_age_check',
                [
                    'group'                   => self::GROUP_NAME,
                    'note'                    => "The age check is intended for parcel shipments for which the recipient must show 18+ by means of a proof of identity. This option can't be combined with morning or evening delivery.",
                    'type'                    => 'varchar',
                    'backend'                 => '',
                    'frontend'                => '',
                    'label'                   => 'Age check 18+',
                    'input'                   => 'select',
                    'class'                   => '',
                    'source'                  => 'MyParcelBE\Magento\Model\Source\AgeCheckOptions',
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
                '`path` = "myparcelbe_magento_postnl_settings/general/deliverydays_window"'
            );
            $allowShowDeliveryDatePath = 'myparcelbe_magento_postnl_settings/general/allow_show_delivery_date';

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
            $insuranceBelgium = 'myparcelbe_magento_postnl_settings/default_options/insurance_belgium_active';
            $connection->delete($table, ['path = ?' => $insuranceBelgium]);
            $connection->insert($table, ['path' => $insuranceBelgium, 'value' => 1]);
        }

        if (version_compare($context->getVersion(), '4.4.0', '<=')) {
            $setup->startSetup();
            $eavSetup
                ->removeAttribute(Product::ENTITY, 'myparcel_digital_stamp')
                ->removeAttribute(Product::ENTITY, 'myparcel_classification')
                ->removeAttribute(Product::ENTITY, 'myparcel_disable_checkout');

            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_digital_stamp',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'label'   => 'Fit in digital stamp',
                        'input'   => 'boolean',
                        'default' => '0',
                    ]
                )
            )
                ->addAttribute(
                    Product::ENTITY,
                    'myparcel_disable_checkout',
                    array_merge(self::DEFAULT_ATTRIBUTES, [
                            'note'    => 'With this option you can disable the delivery options for this product.',
                            'label'   => 'Disable checkout with this product',
                            'input'   => 'boolean',
                            'default' => 0,
                        ]
                    )
                )
                ->addAttribute(
                    Product::ENTITY,
                    'myparcel_classification',
                    array_merge(self::DEFAULT_ATTRIBUTES, [
                            'note'    => 'HS Codes are used for MyParcel world shipments, you can find the appropriate code on the site of the Dutch Customs',
                            'label'   => 'HS code',
                            'input'   => 'text',
                            'default' => '0',
                        ]
                    )
                );
        }

        if (version_compare($context->getVersion(), '4.6.0', '<=')) {
            $setup->startSetup();

            $this->replaceFitInMailbox->updateCatalogProductEntity();
            $eavSetup->removeAttribute(Product::ENTITY, 'myparcel_fit_in_mailbox');
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'type'    => 'varchar',
                        'note'    => 'Fill in the amount of products that fit in a mailbox package. Set to 0 to automatically calculate based on weight.',
                        'label'   => 'Fit in mailbox',
                        'input'   => 'text',
                        'default' => '101',
                        'group'   => self::GROUP_NAME,
                    ]
                )
            );

            $this->replaceFitInMailbox->writeNewAttributeEntity();


            $this->replaceDisableCheckout->indexOldAttribute();
            $eavSetup->removeAttribute(Product::ENTITY, 'myparcel_disable_checkout');
            $eavSetup->addAttribute(
                Product::ENTITY,
                'myparcel_disable_checkout',
                array_merge(self::DEFAULT_ATTRIBUTES, [
                        'note'    => 'With this option you can disable the delivery options if this product is in the cart.',
                        'label'   => 'Disable delivery options',
                        'input'   => 'boolean',
                        'default' => 0,
                    ]
                )
            );

            $this->replaceDisableCheckout->writeNewAttributeEntity();
        }

        if (version_compare($context->getVersion(), '4.8.2', '<')) {
            $eavSetup->updateAttribute(
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                'note',
                'Fill in the amount of products that fit in one mailbox package. Use 0 to automatically calculate based on weight, -1 if the article does not fit in a mailbox package. The product will always be sent as a regular package if it\'s too heavy for a mailbox package.'
            );
            $eavSetup->updateAttribute(
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                'default_value',
                -1
            );
        }
        if (version_compare($context->getVersion(), '4.9.1', '<')) {
            $eavSetup->updateAttribute(
                Product::ENTITY,
                'myparcel_fit_in_mailbox',
                'note',
                'Fill in the amount of products that fit in one mailbox package. Use 0 to automatically calculate based on weight, -1 if the article does not fit in a mailbox package. It will always be sent as a regular package if it\'s too heavy for a mailbox package.'
            );
        }

        if (version_compare($context->getVersion(), '4.12.0', '<')) {
            $this->replaceDpzRange->updateRangeValue();
        }

        if (version_compare($context->getVersion(), '4.14.0', '<')) {
            /**
             * - migrate cutoff_time -> drop off days
             * - migrate monday delivery + saturday cutoff_time -> drop off days
             * - migrate allowShowDeliveryDate from carrier -> general
             * - migrate monday delivery from general -> delivery
             */
            $scopes = [];
            $rows = $connection->fetchAll($connection->select()->distinct()->from($table, ['scope', 'scope_id']));
            foreach ($rows as $row) {
                $scopes[$row['scope']] = (int) $row['scope_id'];
            }
            $rows = null;

            $getConfigRow = static function (string $path, string $scope, int $scopeId) use ($connection, $table) {
                if (! in_array($scope, ['default', 'websites', 'stores'], true)) {
                    echo "\nIgnored invalid scope $scope";
                    return null;
                }

                if (! preg_match('/^[a-z0-9_\/]+$/i', $path)) {
                    echo "\nIgnored invalid path $path";
                    return null;
                }

                $sql = "SELECT config_id, path, value FROM $table WHERE `path` = \"$path\" AND `scope` = \"$scope\" AND `scope_id` = $scopeId";

                return $connection->fetchAll($sql);
            };

            $getConfigValue = static function (string $path, string $scope, int $scopeId) use ($getConfigRow) {
                $row = $getConfigRow($path, $scope, $scopeId);

                return $row[0]['value'] ?? null;
            };

            $allowShowDeliveryDate = false;
            $dropOffDelay = PHP_INT_MAX;
            $deliveryDaysWindow = 0;

            foreach ($scopes as $scope => $scopeId) {
                if ('default' === $scope) {
                    $scopeId = 0;
                }

                if (! in_array($scope, ['default', 'websites', 'stores'], true)) {
                    echo "\nSkipping invalid scope $scope";
                    continue;
                }

                foreach (Data::CARRIERS_XML_PATH_MAP as $carrierName => $carrierPath) {
                    echo "\nMigrating $carrierName for scope $scope ($scopeId)";
                    /**
                     * update the carrier specific date settings to a single setting for later
                     */
                    $carrierPath = trim($carrierPath, '/');
                    $value = (int) $getConfigValue("$carrierPath/general/allow_show_delivery_date", $scope, $scopeId);
                    if ($value) {
                        $allowShowDeliveryDate = (bool) $value;
                        $value = (int) $getConfigValue("$carrierPath/general/deliverydays_window", $scope, $scopeId);
                        if ($value > $deliveryDaysWindow) {
                            $deliveryDaysWindow = $value;
                        }
                        $value = (int) $getConfigValue("$carrierPath/general/dropoff_delay", $scope, $scopeId);
                        if ($value < $dropOffDelay && $value >= 0) {
                            $dropOffDelay = $value;
                        }
                    }

                    /**
                     * migrate monday delivery from general to delivery
                     */
                    $connection->update($table, ['path' => "$carrierPath/delivery/monday_active"], ['path = ?' => "$carrierPath/general/monday_delivery_active"]);

                    /**
                     * migrate drop-off days and cutoff time(s) to drop-off days with individual cutoff times
                     */
                    $cutoffTime = $getConfigValue("$carrierPath/general/cutoff_time", $scope, $scopeId);
                    $saturdayCutoffTime = $getConfigValue("$carrierPath/general/saturday_cutoff_time", $scope, $scopeId);
                    $dropOffDaysString = $getConfigValue("$carrierPath/general/dropoff_days", $scope, $scopeId);

                    if (!$dropOffDaysString) {
                        $dropOffDays = [];
                    } else {
                        $dropOffDays = array_map('intval', explode(',', $dropOffDaysString));
                    }

                    foreach ([0, 1, 2, 3, 4, 5, 6] as $day) {
                        $time = $cutoffTime;

                        if (0 === $day || 6 === $day) {
                            $time = $saturdayCutoffTime;
                        }

                        $updates = [
                            "$carrierPath/drop_off_days/day_{$day}_active" => '1',
                            "$carrierPath/drop_off_days/cutoff_time_$day" => $time,
                        ];

                        foreach ($updates as $path => $value) {
                            $connection->delete($table, ['path = ?' => $path, 'scope = ?' => $scope, 'scope_id = ?' => $scopeId]);

                            if (in_array($day, $dropOffDays, true)) {
                                $connection->insert($table, ['path' => $path, 'value' => $value, 'scope' => $scope, 'scope_id' => $scopeId]);
                            }
                        }
                    }
                }

                if (PHP_INT_MAX === $dropOffDelay) {
                    $dropOffDelay = 0;
                }

                /**
                 * migrate the carrier specific date settings to general settings
                 */
                $updates = [
                    'myparcelbe_magento_general/date_settings/allow_show_delivery_date' => $allowShowDeliveryDate ? '1' : '0',
                    'myparcelbe_magento_general/date_settings/deliverydays_window' => $deliveryDaysWindow,
                    'myparcelbe_magento_general/date_settings/dropoff_delay' => $dropOffDelay,
                ];

                foreach ($updates as $path => $value) {
                    $connection->delete($table, ['path = ?' => $path, 'scope = ?' => $scope, 'scope_id = ?' => $scopeId]);
                    $connection->insert($table, ['path' => $path, 'value' => $value, 'scope' => $scope, 'scope_id' => $scopeId]);
                }
            }
        }

        $setup->endSetup();
    }
}
