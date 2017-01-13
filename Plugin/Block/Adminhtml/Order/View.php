<?php
/**
 * Set the label print button in order view
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

namespace MyParcelNL\Magento\Plugin\Block\Adminhtml\Order;


class View
{
    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        $message ='Ut enim ad minima veniam, quis nostrum exercitationem ullam corporis suscipit laboriosam?';
        $url = $this->getPrintLabelUrl($view);


        $view->addButton(
            'myparcelnl_print_label',
            [
                'label' => __('Print label'),
                'class' => 'myparcelnl_print_label',
                'onclick' => "confirmSetLocation('{$message}', '{$url}')"
            ]
        );
    }

    /**
     * Print label URL getter
     *
     * @param \Magento\Sales\Block\Adminhtml\Order\View $view
     *
     * @return string
     */
    public function getPrintLabelUrl(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        return $view->getUrl('myparcelnl/order/MassTrackTraceLabel', [
            'selected_ids' => $view->getOrderId()
        ]);
    }
}