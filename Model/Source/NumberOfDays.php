<?php
/**
 * Get number of days (to show in the checkout) for MyParcel system settings
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class NumberOfDays implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        $array = [];

        foreach ($this->toArray() as $key => $day) {
            $array[] = [
                'value' => $key,
                'label' => $day,
            ];
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
        $array = [
            'hide' => __('Hide days'),
            1 => '1 ' . __('day'),
        ];

        $x = 2;
        while ($x <= 14) {
            $array[$x] = $x . ' ' . __('days');
            $x++;
        }

        return $array;
    }
}
