<?php
/**
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2016 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Helper;

use Magento\Framework\App\Helper\AbstractHelper;

class Order extends AbstractHelper
{
    /**
     * Checks if the given shipping method is a pickup location
     *
     * @param $method
     *
     * @return bool
     */
    public function isPickupLocation($method)
    {
        if (strpos($method, 'pickup') !== false) {
            return true;
        }

        return false;
    }
}
