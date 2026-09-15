<?php
namespace MyParcelNL\Magento\Model\Sales;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;

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
    /** @throws \RuntimeException when setShipmentCollection() has not run yet */
    protected function getShipmentsCollection(): \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection
    {
        if (null === $this->shipments) {
            throw new \RuntimeException('No shipment collection was set on this export');
        }

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
        $this->forgetTracks();

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
        $tracks = $this->tracksByShipmentId();

        foreach ($this->getShipmentsCollection() as $shipment) {
            if (! ($tracks[(int) $shipment->getId()] ?? []) ||
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
     * One failure is one shipment's: a bounced address or a refused SMTP handshake used to abort the
     * loop, so every shipment after it went unmailed with nothing said. Whether a send blocks the
     * request at all is Magento's own `sales_email/general/async_sending` — with it off, a large
     * batch pays one SMTP round trip per shipment before the label PDF reaches the admin.
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
            if ($shipment->getEmailSent() != null) {
                continue;
            }

            try {
                $this->trackSender->send($shipment);
            } catch (\Throwable $e) {
                Logger::warning(
                    sprintf('MyParcel: the track & trace email for shipment %s could not be sent', $shipment->getId()),
                    LogContext::of($e)
                );
            }
        }

        return $this;
    }
}
