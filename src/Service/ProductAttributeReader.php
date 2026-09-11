<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads one of the module's own product attributes straight out of the EAV value tables.
 *
 * Not the product repository: the callers hold a quote item or an order item and want a single
 * `myparcel_*` column, and loading the product model for it costs far more than the column is worth.
 *
 * Shared because ShipmentOptionsResolver and PackageRepository each carried their own copy of this
 * pair of queries, and had already drifted — only one of them memoised the attribute id.
 *
 * The attribute lookup is scoped to catalog_product. Without it an attribute of the same code on
 * another entity type could win the row, which is a silent wrong answer rather than an error.
 */
class ProductAttributeReader
{
    private const ATTRIBUTE_PREFIX = 'myparcel_';
    private const ENTITY_TYPE_CODE = 'catalog_product';

    private ResourceConnection $resource;

    /** @var array<string,string> attribute code => id. The eav_attribute row cannot change mid-request. */
    private array $attributeIds = [];

    private ?string $entityTypeId = null;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * The value of `myparcel_<$column>` for one product, or null when the attribute or the row is
     * absent.
     *
     * @param string $valueTable an EAV value table, e.g. catalog_product_entity_varchar
     */
    public function value(string $valueTable, string $entityId, string $column): ?string
    {
        $attributeId = $this->attributeId($column);

        if ('' === $attributeId) {
            return null;
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName($valueTable), ['value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $entityId);

        $value = $connection->fetchOne($select);

        return false === $value || null === $value ? null : (string) $value;
    }

    /** The id of `myparcel_<$column>` on catalog_product, or '' when there is no such attribute. */
    public function attributeId(string $column): string
    {
        if (isset($this->attributeIds[$column])) {
            return $this->attributeIds[$column];
        }

        $connection = $this->resource->getConnection();

        // The column is named explicitly. The old copies passed 'entity_type_id' to select(), which
        // takes no arguments, so the query was SELECT * and fetchOne() returned whatever column came
        // first — attribute_id, but by position rather than by name.
        $select = $connection->select()
            ->from($this->resource->getTableName('eav_attribute'), ['attribute_id'])
            ->where('attribute_code = ?', self::ATTRIBUTE_PREFIX . $column)
            ->where('entity_type_id = ?', $this->entityTypeId());

        $id = $connection->fetchOne($select);

        return $this->attributeIds[$column] = false === $id || null === $id ? '' : (string) $id;
    }

    /**
     * The catalog_product entity type id.
     *
     * Its own query rather than a join, so this class needs nothing of a Select but from() and
     * where(). Read once per instance: eav_entity_type does not change while a request runs.
     */
    private function entityTypeId(): string
    {
        if (null !== $this->entityTypeId) {
            return $this->entityTypeId;
        }

        $connection = $this->resource->getConnection();

        $select = $connection->select()
            ->from($this->resource->getTableName('eav_entity_type'), ['entity_type_id'])
            ->where('entity_type_code = ?', self::ENTITY_TYPE_CODE);

        $id = $connection->fetchOne($select);

        return $this->entityTypeId = false === $id || null === $id ? '' : (string) $id;
    }
}
