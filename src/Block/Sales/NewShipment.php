<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsForm;
use MyParcelNL\Magento\ViewModel\ShipmentOptionsFormFactory;

/**
 * The MyParcel part of the admin New Shipment page: the export switch, a summary of the order's
 * shipment options, and the button that opens the shared options modal.
 */
class NewShipment extends Template
{
    private Order               $order;
    private ShipmentOptionsForm $optionsForm;

    public function __construct(
        Context                    $context,
        Registry                   $registry,
        ShipmentOptionsFormFactory $optionsFormFactory,
        array                      $data = []
    )
    {
        $this->order       = $registry->registry('current_shipment')->getOrder();
        $this->optionsForm = $optionsFormFactory->create(['order' => $this->order]);

        parent::__construct($context, $data);
    }

    public function getOptionsForm(): ShipmentOptionsForm
    {
        return $this->optionsForm;
    }

    public function getOrderId(): int
    {
        return (int) $this->order->getId();
    }

    public function getOptionsFormUrl(): string
    {
        return $this->getUrl('myparcel/shipmentOptions/form');
    }

    public function getOptionsSaveUrl(): string
    {
        return $this->getUrl('myparcel/shipmentOptions/save');
    }
}
