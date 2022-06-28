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
        $resource      = $objectManager->get('Magento\Framework\App\ResourceConnection');

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
            ->where(sprintf('eav_attribute.attribute_code = "%s"', $this->attributeName));

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
            ->from('eav_attribute')
            ->where(sprintf('eav_attribute.attribute_code = "%s"', $this->attributeName));

        $this->newEavAttributeId = $connection->fetchOne($query);

        $query = $this->queryBuilder
            ->update('catalog_product_entity_int')
            ->set('attribute_id', (string) $this->newEavAttributeId)
            ->where(sprintf('attribute_id = %s', $this->oldEavAttributeId));
        $connection->query($query);
    }

}
