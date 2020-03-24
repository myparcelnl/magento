<?php
/**
 * Show MyParcel options in order detailpage
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelBE\Magento\Block\Sales;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use MyParcelBE\Magento\Helper\Checkout as CheckoutHelper;
use MyParcelBE\Magento\Model\Quote\Checkout;

class View extends AbstractOrder
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelBE\Magento\Helper\Order
     */
    private $helper;

    /**
     * Constructor
     */
    public function _construct()
    {
        $this->objectManager = ObjectManager::getInstance();
        $this->helper        = $this->objectManager->get('\MyParcelBE\Magento\Helper\Order');
        parent::_construct();
    }

    /**
     * Collect options selected at checkout and calculate type consignment
     *
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getCheckoutOptionsHtml()
    {
        $html  = false;
        $order = $this->getOrder();

        /** @var object $data Data from checkout */
        $data = $order->getData(CheckoutHelper::FIELD_DELIVERY_OPTIONS) !== null ? json_decode($order->getData(CheckoutHelper::FIELD_DELIVERY_OPTIONS), true) : false;

        if ($this->helper->isPickupLocation($data)) {
            if (is_array($data) && key_exists('pickupLocation', $data)) {

                $dateTime = date('d-m-Y H:i', strtotime($data['date']));

                $html .= __($data['carrier'] . ' location:') . ' ' . $dateTime;
                if ($data['deliveryType'] != 'pickup') {
                    $html .= ', ' . __($data['deliveryType']);
                }
                $html .= ', ' . $data['pickupLocation']['location_name'] . ', ' . $data['pickupLocation']['city'] . ' (' . $data['pickupLocation']['postal_code'] . ')';
            } else {
                /** Old data from orders before version 1.6.0 */
                $html .= __('MyParcel options data not found');
            }


        } else {
            if (is_array($data) && key_exists('date', $data)) {
                $dateTime = date('d-m-Y H:i', strtotime($data['date']));
                $html .= __('Deliver:') . ' ' . $dateTime;

                if (key_exists('shipmentOptions', $data)) {
                    if (key_exists('signature', $data['shipmentOptions']) && $data['shipmentOptions']['signature']) {
                        $html .= ', ' . __('Signature on receipt');
                    }
                }
            }
        }

        if (is_array($data) && key_exists('browser', $data)) {
            $html = ' <span title="' . $data['browser'] . '">' . $html . '</span>';
        }

        return $html !== false ? '<br>' . $html : '';
    }
}
