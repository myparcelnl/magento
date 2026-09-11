<?php
/**
 * Get paper types for MyParcel system settings
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PaperType implements OptionSourceInterface
{
    /** The stored values. LabelPositions turns A4 into slots; anything else prints A6. */
    public const A4 = 'A4';
    public const A6 = 'A6';

    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [['value' => self::A4, 'label' => __('A4')], ['value' => self::A6, 'label' => __('A6')]];
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [self::A4 => __('A4'), self::A6 => __('A6')];
    }
}
