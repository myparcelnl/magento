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


use MyParcelNL\Magento\Model\Sales\Delivery;

class DeliveryRepository extends Delivery
{
    /**
     * Get drop off day with chosen options from checkout
     *
     * @param $jsonDeliveryOptions
     * @return string
     */
    public function getDropOffDayFromJson($jsonDeliveryOptions) {
        if ($jsonDeliveryOptions === null) {
            return null;
        }

        $deliveryOptions = json_decode($jsonDeliveryOptions, true);
        if (key_exists('date', $deliveryOptions)) {
            $this->setDeliveryDateTime(strtotime($deliveryOptions['date'] . ' 00:00:00'));
            $dropOffDate = $this->getDropOffDay();

            return date("Y-m-d", $dropOffDate);
        }

        return null;
    }


    /**
     * Get drop off day
     *
     * @return int
     */
    public function getDropOffDay()
    {
        $weekDay = date('N', $this->getDeliveryDateTime());

        switch ($weekDay) {
            case (1): // Monday
                $dropOff = strtotime("-2 day", $this->getDeliveryDateTime());
                break;
            case (2):
            case (3):
            case (4):
            case (5): // Friday
            case (6): // Saturday
            case (7): // Sunday
            default:
                $dropOff = strtotime("-1 day", $this->getDeliveryDateTime());
                break;
        }

        return $dropOff;
    }
}