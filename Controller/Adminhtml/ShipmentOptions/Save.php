<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Controller\Adminhtml\ShipmentOptions;

use MyParcelNL\Magento\Service\ShipmentOptions\OptionChanges;

/**
 * Writes the fields the merchant changed in the modal to every selected order. For one order it
 * answers the new summary, which the New Shipment page shows.
 */
class Save extends AbstractShipmentOptions
{
    protected function answer(array $orders): array
    {
        $this->writer->write($orders, OptionChanges::fromRequest((array) $this->getRequest()->getParam('changes', [])));

        if (1 !== count($orders)) {
            return ['success' => true];
        }

        return ['success' => true, 'summary' => $this->formFactory->create(['order' => $orders[0]])->summary()];
    }
}
