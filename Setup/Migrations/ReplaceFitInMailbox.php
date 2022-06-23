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
            ->leftJoin('eav_attribute ON "' . $this->attributeName . '" = eav_attribute.attribute_code')
            ->where('catalog_product_entity_varchar.attribute_id = eav_attribute.attribute_id');
        $results = $connection->fetchAll($query);

        foreach ($results as $entity) {
            // Set the old attribute id to use it later
            $this->oldEavAttributeId = $entity['attribute_id'];

            // Update the old attribute value to copy it to the new attribute later
            $query = $this->queryBuilder
                ->update('catalog_product_entity_varchar')
                ->set('value', (string) $this->calculatePercentToValue($entity))
                ->where('value_id = ' . $entity['value_id']);
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
        return round((100 / $entity['value']));
    }

    /**
     * @return void
     */
    public function writeNewAttributeEntity(): void
    {
        $connection = $this->resourceConnection();

        // Retrieve the new eav_attribute id
        $query  = $this->queryBuilder
            ->select('*')
            ->from('eav_attribute')
            ->where('eav_attribute.attribute_code = "' . $this->attributeName . '"');
        $result = $connection->fetchRow($query);

        // Set the new eav_attribute -> id
        $this->newEavAttributeId = $result['attribute_id'];

        // Update the old attribute value to copy it to the new attribute later
        $query = $this->queryBuilder
            ->update('catalog_product_entity_varchar')
            ->set('attribute_id', $this->newEavAttributeId)
            ->where('attribute_id = ' . $this->oldEavAttributeId);
        $connection->query($query);
    }
}
