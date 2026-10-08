<?php
/**
 * Set the label print button in order view
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Plugin\Block\Adminhtml\Order;

use MyParcelNL\Magento\Service\AccountSettings\StoredAccount;

/** Order v1 makes no label in Magento: MyParcel makes it in its backoffice, so no button offers one. */
class View
{
    private StoredAccount $storedAccount;

    public function __construct(StoredAccount $storedAccount)
    {
        $this->storedAccount = $storedAccount;
    }

    /**
     * Add MyParcel label print button to order detail page
     *
     * @param \Magento\Sales\Block\Adminhtml\Order\View $view
     */
    public function beforeSetLayout(\Magento\Sales\Block\Adminhtml\Order\View $view)
    {
        if ($this->storedAccount->hasOrderV1ForStore((int) $view->getOrder()->getStoreId())) {
            return;
        }

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
