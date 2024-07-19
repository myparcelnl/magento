<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Migrations;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelBE\Magento\Setup\QueryBuilder;

class ReplaceFitInMailbox
{
    /**
     * @var string
     */
    private $attributeName = 'myparcel_fit_in_mailbox';

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
     * @throws \Exception
     */
    public function updateCatalogProductEntity(): void
    {
        $connection = $this->resourceConnection();

        $query   = $this->queryBuilder
            ->select(
                'catalog_product_entity_varchar.entity_id',
                'catalog_product_entity_varchar.value_id',
                'catalog_product_entity_varchar.value',
                'eav_attribute.attribute_id'
            )
            ->from($this->setup->getTable('catalog_product_entity'), 'product ')
            ->leftJoin($this->setup->getTable('catalog_product_entity_varchar') . ' AS catalog_product_entity_varchar ON product.entity_id = catalog_product_entity_varchar.entity_id')
            ->leftJoin(sprintf($this->setup->getTable('eav_attribute') . ' AS eav_attribute ON \'%s\' = eav_attribute.attribute_code', $this->attributeName))
            ->where('catalog_product_entity_varchar.attribute_id = eav_attribute.attribute_id');
        $results = $connection->fetchAll($query);

        foreach ($results as $entity) {
            $this->oldEavAttributeId = $entity['attribute_id'];

            $query = $this->queryBuilder
                ->update($this->setup->getTable('catalog_product_entity_varchar'))
                ->set('value', (string) $this->calculatePercentToValue($entity))
                ->where(sprintf('value_id = \'%s\'', $entity['value_id']));
            $connection->query($query);
        }
    }

    /**
     * @param $entity
     *
     * @return float
     */
    private function calculatePercentToValue($entity): float
    {
        if (0 === (int) ($entity['value'] ?? 0)) {
            return 0;
        }

        /**
         * 101 percent means: it does not fit in a mailbox.
         * So we set it to -1 to indicate that it does not fit.
         */
        if (101 === (int) $entity['value']) {
            return -1;
        }

        return round((100 / $entity['value']));
    }

    /**
     * @return void
     */
    public function writeNewAttributeEntity(): void
    {
        if (! $this->oldEavAttributeId) {
            return;
        }

        $connection = $this->resourceConnection();

        $query  = $this->queryBuilder
            ->select('*')
            ->from($this->setup->getTable('eav_attribute'), 'eav_attribute')
            ->where(sprintf('eav_attribute.attribute_code = \'%s\'', $this->attributeName));
        $result = $connection->fetchRow($query);

        $this->newEavAttributeId = $result['attribute_id'];

        $query = $this->queryBuilder
            ->update($this->setup->getTable('catalog_product_entity_varchar'))
            ->set('attribute_id', $this->newEavAttributeId)
            ->where(sprintf('attribute_id = %s', $this->oldEavAttributeId));
        $connection->query($query);
    }
}
