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
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 2.0.0
 */

namespace MyParcelBE\Magento\Model\Sales\Repository;

use MyParcelBE\Magento\Model\Sales\Delivery;

class DeliveryRepository extends Delivery
{
    /**
     * Get drop off day with chosen options from checkout
     *
     * @param array $deliveryOptions
     * @return string
     */
    public function getDropOffDayFromDeliveryOptions(array $deliveryOptions): ?string
    {
        if (key_exists('date', $deliveryOptions)) {
            if (! $deliveryOptions['date']) {
                return date('Y-m-d', strtotime("+1 day"));
            }

            $this->setDeliveryDateTime(strtotime($deliveryOptions['date']));
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


    /**
     * Get carrier with chosen options from checkout
     *
     * @param array $deliveryOptions
     * @return string|null
     */
    public function getCarrierFromDeliveryOptions(array $deliveryOptions): ?string
    {
        return $deliveryOptions['carrier'] ?? null;
    }
}
