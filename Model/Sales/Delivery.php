<?php
/**
 * This class contain all methods to check the type of package
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

namespace MyParcelNL\Magento\Model\Sales;


use Magento\Framework\Module\ModuleListInterface;
use MyParcelNL\Magento\Helper\Data;
use Magento\Framework\App\Helper\Context;
use Psr\Log\LoggerInterface;

class Delivery extends Data implements DeliveryInterface
{
    /**
     * @var int
     */
    private $deliveryDateTime;

    /**
     * @return int
     */
    public function getDeliveryDateTime()
    {
        return $this->deliveryDateTime;
    }

    /**
     * @param int $deliveryDateTime
     * @return int
     */
    public function setDeliveryDateTime($deliveryDateTime)
    {
        $this->deliveryDateTime = $deliveryDateTime;
    }
}
