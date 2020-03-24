<?php
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Order extends AbstractHelper
{
    /**
     * Checks if the given shipping method is a pickup location
     *
     * @param $myparcelDeliveryOptions
     *
     * @return bool
     */
    public function isPickupLocation($myparcelDeliveryOptions)
    {
        if(is_array($myparcelDeliveryOptions) && key_exists('isPickup', $myparcelDeliveryOptions) && $myparcelDeliveryOptions['isPickup']){
            return true;
        }

        return false;
    }
}
