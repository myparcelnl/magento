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
     * Get all Magento shipments
     *
     * @return \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
     */
    public function getShipments()
    {
        return $this->shipments;
    }

    /**
     * Set Magento collection
     *
     * @param $shipmentCollection \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
     *
     * @return $this
     */
    public function setShipmentCollection($shipmentCollection)
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
    public function setMagentoTrack()
    {
        /**
         * @var Order          $order
         * @var Order\Shipment $shipment
         */
        foreach ($this->getShipments() as $shipment) {
            if ($this->shipmentHasTrack($shipment) == false ||
                $this->getOption('create_track_if_one_already_exist')
            ) {
                $this->setNewMagentoTrack($shipment);
            }
        }

        $this->getShipments()->save();

        return $this;
    }

    /**
     * Add MyParcel Track from Magento Track
     *
     * @return $this
     * @throws \Exception
     */
    public function setNewMyParcelTracks(): self
    {
        return $this->setNewMyParcelTracksByShipment($this->shipments);
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

    /**
     * Update all the tracks that made created via the API
     *
     * @return $this
     * @throws \Exception
     */
    public function updateMagentoTrack(): self
    {
        return $this->updateMagentoTrackByShipment($this->getShipments());
    }

    /**
     * @return $this
     */
    public function syncMagentoToMyparcel(): self
    {
        return $this->syncMagentoToMyParcelForShipments($this->getShipments());
    }
}
