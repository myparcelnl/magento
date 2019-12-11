<?php

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
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface   $context
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '2.1.23', '<=')) {
            $setup->startSetup();
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

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
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

            // Label of the group
            $groupName = 'MyParcel Options';
            // get entity type id so that attribute are only assigned to catalog_product
            $entityTypeId = $eavSetup->getEntityTypeId('catalog_product');
            // Here we have fetched all attribute set as we want attribute group to show under all attribute set
            $attributeSetIds = $eavSetup->getAllAttributeSetIds($entityTypeId);

            foreach ($attributeSetIds as $attributeSetId) {
                $eavSetup->addAttributeGroup($entityTypeId, $attributeSetId, $groupName, 19);
                $attributeGroupId = $eavSetup->getAttributeGroupId($entityTypeId, $attributeSetId, $groupName);

                // Add existing attribute to group
                $attributeId = $eavSetup->getAttributeId($entityTypeId, 'myparcel_fit_in_mailbox');
                $eavSetup->addAttributeToGroup($entityTypeId, $attributeSetId, $attributeGroupId, $attributeId, null);
            }
        }

        // Add the option 'Fit in digital stamp'
        if (version_compare($context->getVersion(), '2.5.0', '<=')) {
            $setup->startSetup();
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'group'                   => 'MyParcel Options',
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
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

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

        // Add the option 'HS code for products'
        if (version_compare($context->getVersion(), '3.1.3', '<=')) {
            $setup->startSetup();
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_classification',
                [
                    'group'                   => 'MyParcel Options',
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
        }

        // This migration is necessary because the migration for version 3.1.0 was not correct used.
        // The data in the database was not filled in correctly, that was the reason why DPZ and BBP were not visible in the settings.
        if (version_compare($context->getVersion(), '3.1.4', '<=')) {
            $setup->startSetup();
            /** @var EavSetup $eavSetup */
            $eavSetup = $this->eavSetupFactory->create(['setup' => $setup]);

            // Add attributes to the eav/attribute
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'myparcel_digital_stamp',
                [
                    'group'                   => 'MyParcel Options',
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
                    'group'                   => 'MyParcel Options',
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



        $setup->endSetup();
    }
}
