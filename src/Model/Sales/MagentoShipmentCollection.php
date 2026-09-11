<?php
namespace MyParcelNL\Magento\Model\Sales;

use Magento\Sales\Model\Order;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelNL\Magento\Model\Sales
 */
class MagentoShipmentCollection extends MagentoCollection
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\order\shipment\Collection
     */
    private $shipments = null;

    /**
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
     */
    public function getShipments()
    {
        return $this->getShipmentsCollection();
    }
    /**
     * Get all Magento shipments
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
     */
    protected function getShipmentsCollection(): \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
    {
        return $this->shipments;
    }

    /**
     * Set Magento collection
     *
     * @param \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $shipmentCollection
     *
     * @return $this
     */
    public function setShipmentCollection(
        \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $shipmentCollection
    ): self
    {
        $this->shipments = $shipmentCollection;

        return $this;
    }

    /**
     * Create new Magento Track and save order
     *
     * @return $this
     * @throws \Exception
     */
    public function setMagentoTrack(): self
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getShipmentsCollection() as $shipment) {
            if ($this->shipmentHasTrack($shipment) == false ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                $this->setNewMagentoTrack($shipment);
            }
        }

        $this->getShipmentsCollection()->save();

        return $this;
    }




    /**
     * Send shipment email with Track and trace variable
     *
     * @return $this
     */
    public function sendTrackEmailFromShipments()
    {
        /**
         * @var \Magento\Sales\Model\Order\Shipment $shipment
         */
        if ($this->trackSender->isEnabled() == false) {
            return $this;
        }

        foreach ($this->shipments as $shipment) {
            if ($shipment->getEmailSent() == null) {
                $this->trackSender->send($shipment);
            }
        }

        return $this;
    }
}
