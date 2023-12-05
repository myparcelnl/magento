<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Setup\Migrations;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Setup\SchemaSetupInterface;
use MyParcelNL\Magento\Setup\QueryBuilder;

class ReplaceDpzRange
{
    /** @var string $pathName */
    private string $pathName = 'myparcelnl_magento_postnl_settings/digital_stamp/active';

    /** @var \MyParcelNL\Magento\Setup\QueryBuilder $queryBuilder */
    private QueryBuilder $queryBuilder;

    /** @var \Magento\Framework\Setup\SchemaSetupInterface $setup */
    private SchemaSetupInterface $setup;

    /** @var array $replaceValues */
    private array $replaceValues = [
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
            ->where('path = ?', $this->pathName);

        $result = $connection->fetchRow($query);
        if ($result && in_array($result['value'], $this->replaceValues, true)) {
            $query = $this->queryBuilder
                ->update($this->setup->getTable('core_config_data'))
                ->set('value', $this->newValue)
                ->where('path = ?', $this->pathName);
            $connection->query($query);
        }
    }
}
