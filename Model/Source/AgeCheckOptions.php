<?php
/**
 * Get percentages of the volume of the product. So we can figure out later whether the product fits into a mailbox.
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Adem Demir <adem@myparcel.nl>
 * @copyright   2010-2021 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Helper\Data;

class AgeCheckOptions extends AbstractSource
{
    /**
     * @var Data
     */
    static private $helper;
    /**
     * Insurance constructor.
     *
     * @param $order Order
     * @param $helper Data
     */
    public function __construct(Data $helper)
    {
        self::$helper = $helper;
    }
    /**
     * @param $option
     *
     * @return bool
     */
    public function getDefault($option)
    {
        $settings = self::$helper->getStandardConfig('default_options');

        if ($settings[$option . '_active'] == '1') {
            return true;
        }

        return false;
    }
    /**
     * Get age check options
     *
     * @return array
     */
    public function getOptionArray()
    {
        if ($this->getDefault('age_check')) {
            return [['value' => 1, 'label' => __('Yes')]];
        }
        return [
            ['value' => 1, 'label'=>__('Yes')],
            ['value' => 0, 'label'=>__('No')],
        ];
    }

    /**
     * Retrieve All options
     *
     * @return array
     */
    public function getAllOptions()
    {
        return $this->getOptionArray();
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