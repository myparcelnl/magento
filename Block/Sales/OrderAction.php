<?php
/**
 * Block for order actions (multiple orders action and one order action)
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

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\App\ObjectManager;
use myparcelnl\sdk\src\Model\MyParcelClassConstants;

class OrderAction extends OrdersAction
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @param Context                     $context
     * @param array                       $data
     * @param \Magento\Framework\Registry $registry
     */
    public function __construct(Context $context,
                                array $data = [],
                                \Magento\Framework\Registry $registry
    )
    {
        // Set order
        $this->order = $registry->registry('sales_order');
        parent::__construct($context, $data);
    }

    /**
     * @return bool
     */
    public function canShip()
    {
        return $this->order->canShip();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCountry()
    {
        return $this->order->getShippingAddress()->getCountryId();
    }

    /**
     * Check if the address is outside the EU
     *
     * @return bool
     */
    public function isCdCountry()
    {
        return !in_array(
            $this->getCountry(),
            MyParcelClassConstants::EU_COUNTRIES
        );
    }
}
