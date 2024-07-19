<?php
/**
 * The delivery_options interface
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @copyright   2010-2019 MyParcel
 * @since       File available since Release v2.0.0
 */

namespace MyParcelBE\Magento\Api;

/**
 * Get delivery options
 */
interface DeliveryOptionsInterface
{
    /**
     * Return all delivery options
     *
     * @return mixed[] All options
     * @api
     */
    public function get();

    /**
     * Return delivery options suitable for the supplied shippingAddress
     *
     * @param mixed $shippingAddress indexed array holding one shippingAddress object
     *
     * @return mixed
     * @api
     */
    public function configForShippingAddress($shippingAddress);

}
