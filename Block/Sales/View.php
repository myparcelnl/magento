<?php
/**
 * Show MyParcel options in order detailpage
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

use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\ObjectManager;

class View extends \Magento\Sales\Block\Adminhtml\Order\AbstractOrder
{
    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var \MyParcelNL\Magento\Helper\Order
     */
    private $helper;

    /**
     * Constructor
     */
    public function _construct() {
        $this->objectManager = ObjectManager::getInstance();
        $this->helper = $this->objectManager->get('\MyParcelNL\Magento\Helper\Order');
        parent::_construct();
    }

    /**
     * Collect options selected at checkout and calculate type consignment
     *
     * @return string
     */
    public function getCheckoutOptionsHtml()
    {
        $html = false;
        $order = $this->getOrder();

        /** @var object $data Data from checkout */
        $data = $order->getData('delivery_options') !== null ? json_decode($order->getData('delivery_options'), true) : false;
        $shippingMethod = $order->getShippingMethod();

        if ($this->helper->isPickupLocation($shippingMethod))
        {
            if(is_array($data) && key_exists('location', $data)){

                $dateTime = date('d-m-Y H:i', strtotime($data['date'] . ' ' . $data['start_time']));

                $html .= __('PostNL location:') . ' ' . $dateTime;
                if($data['price_comment'] != 'retail')
                    $html .= ', ' . __($data['price_comment']);
                $html .= ', ' . $data['location']. ', ' . $data['city']. ' (' . $data['postal_code']. ')';
            } else {
                /** Old data from orders before version 1.6.0 */
                $html .= __('MyParcel options data not found');
            }
        } else {
            if(is_array($data) && key_exists('date', $data)){

                $dateTime = date('d-m-Y H:i', strtotime($data['date']. ' ' . $data['time'][0]['start']));
                $html .= __('Deliver:') .' ' . $dateTime;

                if(isset($data['time'][0]['price_comment']) && $data['time'][0]['price_comment'] != 'standard')
                    $html .=  ', ' . __($data['time'][0]['price_comment']);

                if (key_exists('options', $data)) {
                    if(key_exists('only_recipient', $data['options']) && $data['options']['only_recipient'])
                        $html .=  ', ' . strtolower(__('Home address only'));
                    if(key_exists('signature', $data['options']) && $data['options']['signature'])
                        $html .=  ', ' . strtolower(__('Signature on receipt'));
                }
            }
        }

        if (is_array($data) && key_exists('browser', $data))
            $html = ' <span title="'.$data['browser'].'">' . $html . '</span>';

        return $html !== false ? '<br>' . $html : '';
    }
}
