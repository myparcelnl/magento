<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\App\ResourceConnection;
use MyParcelNL\Magento\Setup\UpgradeData;

/**
 * Sorts the MyParcel product attributes in the order of the PDK product settings, in every attribute set.
 *
 * An attribute a merchant moved to another group keeps its place there. Run it after the last
 * addAttribute: an attribute added later goes to the end of the group. Idempotent.
 */
class SortProductAttributes
{
    private const ORDER = [
        'myparcel_fit_in_mailbox',
        'myparcel_digital_stamp',
        'myparcel_dropoff_delay',
        'myparcel_disable_checkout',
        'myparcel_exclude_parcel_lockers',
        'myparcel_classification',
        'myparcel_external_product_reference',
        'myparcel_age_check',
        'myparcel_priority_delivery',
    ];

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function run(EavSetup $eavSetup): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('eav_entity_attribute');
        $groups     = $this->resourceConnection->getTableName('eav_attribute_group');
        $inGroup    = "attribute_group_id IN (SELECT attribute_group_id FROM {$groups} WHERE attribute_group_name = ?)";

        foreach (self::ORDER as $position => $code) {
            $attributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, $code);

            if (0 === $attributeId) {
                continue;
            }

            $connection->update(
                $table,
                ['sort_order' => $position + 1],
                ['attribute_id = ?' => $attributeId, $inGroup => UpgradeData::GROUP_NAME]
            );
        }
    }
}
