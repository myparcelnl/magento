<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;

/**
 * Moves the HS code from integer storage to string storage.
 *
 * `myparcel_classification` inherited `'type' => 'int'` from UpgradeData::DEFAULT_ATTRIBUTES while
 * being an `input => 'text'` field, so every code was stored in an INT column. That loses a leading
 * zero (004 becomes 4), cannot hold a dot (6109.10), and clamps anything above 2147483647 to it.
 *
 * Values move as they are. Nothing can recover what the INT column already destroyed — 4 no longer
 * knows it was 004 — so this preserves what survived rather than pretending to repair it.
 */
class ClassificationToVarchar
{
    private const ATTRIBUTE_CODE = 'myparcel_classification';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function run(EavSetup $eavSetup): void
    {
        $attributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);

        if (0 === $attributeId) {
            return;
        }

        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'backend_type', 'varchar');
        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'frontend_class', 'validate-length maximum-length-18');
        $eavSetup->updateAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, 'default_value', '');

        $this->moveValues($attributeId);
    }

    /**
     * Zeros are dropped rather than moved: 0 was the old default, not a code anyone entered, and
     * carrying it over would leave every product exporting a literal "0".
     */
    private function moveValues(int $attributeId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $int        = $this->resourceConnection->getTableName('catalog_product_entity_int');
        $varchar    = $this->resourceConnection->getTableName('catalog_product_entity_varchar');

        $connection->query(
            "INSERT INTO {$varchar} (attribute_id, store_id, entity_id, value)
             SELECT attribute_id, store_id, entity_id, CAST(value AS CHAR)
             FROM {$int}
             WHERE attribute_id = :attribute_id AND value IS NOT NULL AND value <> 0
             ON DUPLICATE KEY UPDATE value = VALUES(value)",
            ['attribute_id' => $attributeId]
        );

        $connection->delete($int, ['attribute_id = ?' => $attributeId]);
    }
}
