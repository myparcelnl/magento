<?php
/**
 * Set the label print button in order view
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Plugin\Block\Adminhtml\Order;

class View
{
    /**
     * Add MyParcel label print button to order detail page
     *
     * @param \Magento\Sales\Block\Adminhtml\Order\View $view
     */
    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        $view->addButton(
            'myparcelnl_print_label',
            [
                'label' => __('Print label'),
                'class' => 'action-myparcel',
            ]
        );
        if ($view->getOrder()->hasShipments() == true) {
            $view->addButton(
                'myparcelnl_print_retour_label',
                [
                    'label' => __('Send return label'),
                    'class' => 'action-myparcel_send_return_mail',
                ]
            );
        }
    }
}
