<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use MyParcelNL\Magento\Setup\QueryBuilder;

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
     * @var \MyParcelNL\Magento\Setup\QueryBuilder
     */
    private $queryBuilder;

    /**
     * @param  \MyParcelNL\Magento\Setup\QueryBuilder $queryBuilder
     */
    public function __construct(
        QueryBuilder    $queryBuilder
    ) {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * @return object
     */
    private function resourceConnection(): object
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');

        return $resource->getConnection();
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
            ->from('catalog_product_entity', 'product ')
            ->leftJoin('catalog_product_entity_varchar ON product.entity_id = catalog_product_entity_varchar.entity_id')
            ->leftJoin(sprintf('eav_attribute ON "%s" = eav_attribute.attribute_code', $this->attributeName))
            ->where('catalog_product_entity_varchar.attribute_id = eav_attribute.attribute_id');
        $results = $connection->fetchAll($query);

        foreach ($results as $entity) {
            $this->oldEavAttributeId = $entity['attribute_id'];

            $query = $this->queryBuilder
                ->update('catalog_product_entity_varchar')
                ->set('value', (string) $this->calculatePercentToValue($entity))
                ->where(sprintf('value_id = "%s"', $entity['value_id']));
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
        return (null !== $entity['value']) ? round((100 / $entity['value'])) : $entity['value'];
    }

    /**
     * @return void
     */
    public function writeNewAttributeEntity(): void
    {
        $connection = $this->resourceConnection();

        $query  = $this->queryBuilder
            ->select('*')
            ->from('eav_attribute')
            ->where(sprintf('eav_attribute.attribute_code = "%s"', $this->attributeName));
        $result = $connection->fetchRow($query);

        $this->newEavAttributeId = $result['attribute_id'];

        $query = $this->queryBuilder
            ->update('catalog_product_entity_varchar')
            ->set('attribute_id', $this->newEavAttributeId)
            ->where(sprintf('attribute_id = %s', $this->oldEavAttributeId));
        $connection->query($query);
    }
}
