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
    protected $request;

    public function __construct(\Magento\Framework\HTTP\PhpEnvironment\Request $request)
    {
       $this->request = $request->setPathInfo();
    }

    /**
     * Avoid default email is sent.
     *
     * With a MyParcel shipment, the mail should be sent only if the barcode exists.
     *
     * @return string|null
     */
    public function afterGetDeliveryOptions()
    {

        $objectManager =  ObjectManager::getInstance();

        $resource = $objectManager->get('Magento\Framework\App\ResourceConnection');
        $connection = $resource->getConnection();
        $tableName = $resource->getTableName('sales_order'); //gives table name with prefix

        $entityId  = str_replace("/rest/V1/orders/","" ,$this->request->getPathInfo());;

        //Select Data from table
        $sql = $connection
            ->select('delivery_options')
            ->from($tableName)
            ->where('entity_id = '. $entityId);

        $result = $connection->fetchAll($sql); // gives associated array, table fields as key in array.

        return $result[0]['delivery_options'];
    }
}