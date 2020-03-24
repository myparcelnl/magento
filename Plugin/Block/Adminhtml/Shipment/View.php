<?php
/**
 * Set the label print button in order view
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Plugin\Block\Adminhtml\Shipment;

class View
{
    /**
     * Add MyParcel label print button to order detail page
     *
     * @param \Magento\Shipping\Block\Adminhtml\View $view
     */
    public function beforeGetPrintUrl(\Magento\Shipping\Block\Adminhtml\View $view)
    {
        $view->addButton(
            'myparcelbe_print_label',
            [
                'label' => __('Print label'),
                'class' => 'action-myparcel',
            ]
        );
    }
}