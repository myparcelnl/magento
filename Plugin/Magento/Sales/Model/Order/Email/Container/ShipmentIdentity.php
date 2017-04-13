<?php
/**
 * Check Magento Sales Shipment email should be ignored
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 1.0.2
 */
namespace MyParcelNL\Magento\Plugin\Magento\Sales\Model\Order\Email\Container;

use Magento\Framework\App\ObjectManager;

class ShipmentIdentity
{
    /**
     * Avoid default email is sent.
     *
     * With a MyParcel shipment, the mail should be sent only if the barcode exists.
     *
     * @param \Magento\Sales\Model\Order\Email\Container\ShipmentIdentity $subject
     * @param                                                             $result
     *
     * @return bool
     */
    public function afterIsEnabled(
        \Magento\Sales\Model\Order\Email\Container\ShipmentIdentity $subject,
        $result
    ) {
        $objectManager =  ObjectManager::getInstance();

        /**
         * @var \Magento\Framework\App\Request\Http $request
         */
        $request = $objectManager->get('\Magento\Framework\App\Request\Http');

        return $request->getParam('myparcel_track_email') == true ? false : $result;
    }
}
