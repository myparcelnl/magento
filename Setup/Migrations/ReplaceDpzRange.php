<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Setup\Migrations;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelBE\Magento\Setup\QueryBuilder;

class ReplaceDpzRange
{
    /** @var string $pathDigitalStampDefault */
    private $pathDigitalStampDefault  = 'myparcelbe_magento_postnl_settings/digital_stamp/default_weight';

    /** @var \MyParcelBE\Magento\Setup\QueryBuilder $queryBuilder */
    private $queryBuilder;

    /** @var \Magento\Framework\Setup\SchemaSetupInterface $setup */
    private $setup;

    /** @var array $replaceValues */
    private $replaceValues = [
        '100',
        '350',
    ];

    /** @var $newValue */
    private $newValue = '200';

    public function __construct(
        QueryBuilder         $queryBuilder,
        SchemaSetupInterface $setup
    ) {
        $this->queryBuilder = $queryBuilder;
        $this->setup        = $setup;
    }

    /**
     * @return object
     */
    public function resourceConnection(): object
    {
        $objectManager = ObjectManager::getInstance();

        return $objectManager->get('Magento\Framework\App\ResourceConnection')
            ->getConnection();
    }

    /**
     * @throws \Zend_Db_Exception
     */
    public function updateRangeValue(): void
    {
        $connection = $this->resourceConnection();

        /** @var $query */
        $query = $this->queryBuilder
            ->select('path', 'value')
            ->from($this->setup->getTable('core_config_data'))
            ->where(sprintf('path = \'%s\'', $this->pathDigitalStampDefault));

        $result = $connection->fetchRow($query);
        if ($result && in_array($result['value'], $this->replaceValues, true)) {
            $query = $this->queryBuilder
                ->update($this->setup->getTable('core_config_data'))
                ->set('value', $this->newValue)
                ->where(sprintf('path = \'%s\'', $this->pathDigitalStampDefault));
            $connection->query($query);
        }
    }
}
