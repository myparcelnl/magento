<?php

namespace MyParcelBE\Magento\Model\Source;

use Magento\Sales\Model\Order\ShipmentFactory;

class SourceItem
{
    /**
     * @var ShipmentFactory
     */
    private $shipmentFactory;

    public function __construct(
        ShipmentFactory $shipmentFactory
    ) {
        $this->shipmentFactory = $shipmentFactory;
    }

    /**
     * Magento uses an afterCreate plugin on the shipmentFactory to set the SourceCode. In the default flow Magento
     * runs this code when you open the Create Shipment page. This behaviour doesn't occur in this flow, so we force
     * that flow to happen here.
     *
     * @param $order
     * @param $shipmentItems
     *
     */
    public function getSource($order, $shipmentItems)
    {
        $shipment = $this->shipmentFactory->create(
            $order,
            $shipmentItems
        );

        $extensionAttributes = $shipment->getExtensionAttributes();
        return $extensionAttributes->getSourceCode();
    }
}
