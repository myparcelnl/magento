<?php
namespace MyParcelBE\Magento\Model\Sales;

use Magento\Sales\Model\Order;

/**
 * Class MagentoOrderCollection
 *
 * @package MyParcelBE\Magento\Model\Sales
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
     * Set PDF content and convert status 'Concept' to 'Registered'
     *
     * @return $this
     * @throws \Exception
     */
    public function setPdfOfLabels()
    {
        $this->myParcelCollection->setPdfOfLabels($this->options['positions']);

        return $this;
    }

    /**
     * Download PDF directly
     *
     * @return $this
     * @throws \Exception
     */
    public function downloadPdfOfLabels()
    {
        $inlineDownload = $this->options['request_type'] == 'open_new_tab';
        $this->myParcelCollection->downloadPdfOfLabels($inlineDownload);

        return $this;
    }

    /**
     * Update MyParcel collection
     *
     * @return $this
     * @throws \Exception
     */
    public function setLatestData()
    {
        $this->myParcelCollection->setLatestData();

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
