<?php
/**
 * Get percentages of the product. So we can figure out later whether the product fits into a mailbox.
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

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class Volume extends AbstractSource
{
    /**
     * Get percentages of the product. So we can figure out later whether the product fits into a mailbox.
     *
     * @return array
     */
    public function getOptionArray()
    {
        return [
            ['value' => 1, 'label'=>__('Label-1')],
            ['value' => 2, 'label'=>__('Label-2')],
        ];
    }

    /**
     * Retrieve All options
     *
     * @return array
     */
    public function getAllOptions()
    {
        // TODO: Implement getAllOptions() method.
    }
}
