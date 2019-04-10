<?php
/**
 * This class contain all functions to check type of package
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl/magento
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelNL\Magento\Model\Sales\Repository;

use Magento\Framework\ObjectManagerInterface;

class TrackRepository
{
    private $objectManager;


    public function __construct(ObjectManagerInterface $objectManagerInterface)
    {
        $this->objectManager = $objectManagerInterface;
    }

    /**
     * @param $barcode
     *
     * @return string
     */
    public function getPostalCodeFromBarcode($barcode)
    {
        /** @var \Magento\Framework\App\ResourceConnection $connection */
        $connection = $this->objectManager->create('\Magento\Framework\App\ResourceConnection');
        $conn = $connection->getConnection();
        $select = $conn->select()
                       ->from(
                           ['main_table' => $connection->getTableName('sales_shipment_track')]
                       )
                       ->where('main_table.track_code=?', $barcode);
        $tracks = $conn->fetchAll($select);




        var_dump($tracks);
        exit("\n|-------------\n" . __FILE__ . ':' . __LINE__ . "\n|-------------\n");
    }
}