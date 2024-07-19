<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Migrations;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelBE\Magento\Setup\QueryBuilder;

class ReplaceDisableCheckout
{
    /**
     * @var string
     */
    private $attributeName = 'myparcel_disable_checkout';

    /**
     * @var int
     */
    private $oldEavAttributeId;

    /**
     * @var int
     */
    private $newEavAttributeId;

    /**
     * @var \MyParcelBE\Magento\Setup\QueryBuilder
     */
    private $queryBuilder;

    /**
     * @var \Magento\Framework\Setup\SchemaSetupInterface
     */
    private $setup;

    /**
     * @param  \MyParcelBE\Magento\Setup\QueryBuilder        $queryBuilder
     * @param  \Magento\Framework\Setup\SchemaSetupInterface $setup
     */
    public function __construct(
        QueryBuilder    $queryBuilder,
        SchemaSetupInterface $setup
    ) {
        $this->queryBuilder = $queryBuilder;
        $this->setup = $setup;
    }

    /**
     * @return object
     */
    private function resourceConnection(): object
    {
        $objectManager = ObjectManager::getInstance();

        return $objectManager->get('Magento\Framework\App\ResourceConnection')
            ->getConnection();
    }

    /**
     * @return void
     */
    public function indexOldAttribute(): void
    {
        $connection = $this->resourceConnection();

        $query = $this->queryBuilder
            ->select('*')
            ->from($this->setup->getTable('eav_attribute'), 'eav_attribute')
            ->where(sprintf('eav_attribute.attribute_code = \'%s\'', $this->attributeName));

        $this->oldEavAttributeId = $connection->fetchOne($query);
    }

    /**
     * @return void
     */
    public function writeNewAttributeEntity(): void
    {
        $connection = $this->resourceConnection();

        $query = $this->queryBuilder
            ->select('*')
            ->from($this->setup->getTable('eav_attribute'), 'eav_attribute')
            ->where(sprintf('eav_attribute.attribute_code = \'%s\'', $this->attributeName));

        $this->newEavAttributeId = $connection->fetchOne($query);

        $query = $this->queryBuilder
            ->update($this->setup->getTable('catalog_product_entity_int'))
            ->set('attribute_id', (string) $this->newEavAttributeId)
            ->where(sprintf('attribute_id = %s', $this->oldEavAttributeId));
        $connection->query($query);
    }
}
