<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use MyParcelNL\Magento\Setup\QueryBuilder;

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
        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        return $resource->getConnection();
    }

    /**
     * @return void
     */
    public function indexOldAttribute(): void
    {
        $connection = $this->resourceConnection();

        $query = $this->queryBuilder
            ->select('*')
            ->from('eav_attribute')
            ->where('eav_attribute.attribute_code = "'. $this->attributeName .'"');

        $this->oldEavAttributeId = $connection->fetchOne($query);
    }

    /**
     * @return void
     */
    public function writeNewAttributeEntity(): void
    {
        $connection = $this->resourceConnection();

        // Get the new attribute by attributeName
        $query = $this->queryBuilder
            ->select('*')
            ->from('eav_attribute')
            ->where('eav_attribute.attribute_code = "'. $this->attributeName .'"');

        // Set the new attribute ID
        $this->newEavAttributeId = $connection->fetchOne($query);

        // Update the new fields of the last set eav_attribute
        $query = $this->queryBuilder
            ->select('catalog_product_entity_int.entity_id', 'catalog_product_entity_int.value', 'eav_attribute.attribute_id')
            ->from('catalog_product_entity', 'product ')
            ->leftJoin('catalog_product_entity_int ON product.entity_id = catalog_product_entity_int.entity_id')
            ->leftJoin('eav_attribute ON "'. $this->attributeName .'" = eav_attribute.attribute_code')
            ->where('catalog_product_entity_int.attribute_id = '. $this->oldEavAttributeId);
        $results = $connection->fetchAll($query);

        foreach($results as $entity) {
            // Update the old attribute value to copy it to the new attribute later
            $query = $this->queryBuilder
                ->update('catalog_product_entity_int')
                ->set('attribute_id', $this->newEavAttributeId)
                ->where('attribute_id = '. $this->oldEavAttributeId);
            $connection->query($query);
        }
    }

}
