<?php

/**
 * Created by PhpStorm.
 * User: richardperdaan
 * Date: 2019-03-05
 * Time: 16:23
 */

namespace MyParcelBE\Magento\Plugin\Magento\Sales\Api\Data;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use MyParcelNL\Sdk\src\Support\Arr;

class OrderExtension
{
    const ENTITY_ID          = 'entity_id';
    const INCREMENT_ID       = 'increment_id';
    const ENTITY_ID_POSITION = 4;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\Request
     */
    protected $request;

    /**
     * @var ObjectManager
     */
    private $objectManager;

    /**
     * OrderExtension constructor.
     *
     * @param \Magento\Framework\HTTP\PhpEnvironment\Request $request
     */
    public function __construct(Request $request)
    {
        $this->request       = $request->setPathInfo();
        $this->objectManager = ObjectManager::getInstance();
    }

    /**
     * Use the delivery_options data from the sales_order table so it can be used in the magento rest api.
     *
     * @return string|null
     */
    public function afterGetDeliveryOptions()
    {
        if (strpos($this->request->getPathInfo(), "/rest/V1/orders") === false) {
            return null;
        }

        $resource    = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection  = $resource->getConnection();
        $tableName   = $resource->getTableName('sales_order'); // Gives table name with prefix
        $path        = $this->request->getPathInfo();
        $explodePath = explode('/', $path);

        if (empty($explodePath[self::ENTITY_ID_POSITION])) {
            $searchColumn = self::INCREMENT_ID;
            $searchValue  = $this->getIdByIncrementId();
        } else {
            $searchColumn = self::ENTITY_ID;
            $searchValue  = $explodePath[self::ENTITY_ID_POSITION];
        }

        if (empty($searchValue)) {
            return '';
        }

        //Select Data from table
        $sql = $connection
            ->select('myparcel_delivery_options')
            ->from($tableName)
            ->where($searchColumn . ' = ' . (int) $searchValue);

        $result = $connection->fetchAll($sql); // Gives associated array, table fields as key in array.

        if (empty($result)) {
            return null;
        }

        return $result[0]['myparcel_delivery_options'];
    }

    /**
     * @return mixed
     */
    private function getIdByIncrementId()
    {
        $searchValue = $this->request->getQueryValue('searchCriteria');
        $searchValue = Arr::get($searchValue, 'filterGroups.0.filters.0.value');

        return $searchValue;
    }
}
