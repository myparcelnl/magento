<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup;

use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\CollectionFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

class Uninstall implements UninstallInterface
{
    /**
     * @throws \Exception
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $objectManager        = ObjectManager::getInstance();
        $eavCollectionFactory = $objectManager->get(CollectionFactory::class);
        $eavResourceModel     = $objectManager->get(Attribute::class);
        $eavTypeCollection    = $eavCollectionFactory->create();

        $eavTypeCollection->addFieldToFilter('attribute_code', ['like' => ['myparcel_%']]);

        foreach ($eavTypeCollection as $eavAttribute) {
            $eavResourceModel->delete($eavAttribute);
        }

        $setup->endSetup();
    }
}
