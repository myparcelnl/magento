<?php
/**
 * Get all Drop off days for MyParcel system settings
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
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;


class DropOffDelayDays implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get all Drop off days
     *
     * @return array
     */
    public function toOptionArray()
    {
        $array = [
            [
                'value' => 0,
                'label' => __('No delay'),
            ],
            [
                'value' => 1,
                'label' => 1 . ' ' . __('day'),
            ],
        ];

        $x = 2;
        while($x <= 14) {
            $array[] = [
                'value' => $x,
                'label' => $x . ' ' . __('days')
            ];
            $x++;
        }

        return $array;
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        $array = [];

        foreach ($this->toOptionArray() as $option) {
            $array[] = $option['label'];
        }

        return $array;
    }
}
