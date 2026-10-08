<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions;

use Magento\Backend\Block\Template;

/** The shipment options form for one order, or for a bulk selection of one account's orders. */
class Form extends AbstractShipmentOptions
{
    protected function answer(array $orders): array
    {
        $this->writer->assertOneAccount($orders);

        $html = $this->_view->getLayout()
            ->createBlock(Template::class)
            ->setTemplate('MyParcelNL_Magento::shipment_options/form.phtml')
            ->setData('options_form', $this->formFactory->create(['order' => $orders[0], 'bulk' => count($orders) > 1]))
            ->setData('order_count', count($orders))
            ->toHtml();

        return ['html' => $html];
    }
}
