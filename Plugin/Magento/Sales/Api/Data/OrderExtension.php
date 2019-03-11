<?php
/**
 * Created by PhpStorm.
 * User: richardperdaan
 * Date: 2019-03-05
 * Time: 16:23
 */

namespace MyparcelNL\Magento\Plugin\Magento\Sales\Api\Data;

use Magento\Framework\App\ObjectManager;

class OrderExtension
{

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
    public function __construct(\Magento\Framework\HTTP\PhpEnvironment\Request $request)
    {
       $this->request = $request->setPathInfo();
       $this->objectManager = ObjectManager::getInstance();
    }

    /**
     * Use the delivery_options data from the sales_order table so it can be used in the magento rest api.
     *
     * @return mixed
     */
    public function afterGetDeliveryOptions()
    {
        $resource = $this->objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('sales_order'); // Gives table name with prefix

        $entityId  = str_replace("/rest/V1/orders/","" ,$this->request->getPathInfo());

        //Select Data from table
        $sql = $connection
            ->select('delivery_options')
            ->from($tableName)
            ->where('entity_id = '. $entityId);

        $result = $connection->fetchAll($sql); // Gives associated array, table fields as key in array.

        return $result[0]['delivery_options'];
    }
}
