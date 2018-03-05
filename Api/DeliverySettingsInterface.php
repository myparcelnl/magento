<?php
/**
 * The delivery_settings interface
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v2.0.0
 */

namespace MyParcelNL\Magento\Api;


/**
 * Get delivery settings
 */
Interface DeliverySettingsInterface
{
    /**
     * Return all delivery Settings settings
     *
     * @api
     * @return mixed[] All Settings
     */
    public function get();
}
