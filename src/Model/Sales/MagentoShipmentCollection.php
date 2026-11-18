<?php
namespace MyParcelNL\Magento\Model\Sales;

use Magento\Sales\Model\Order;
use MyParcelNL\Magento\Facade\Logger;
use MyParcelNL\Magento\Service\LogContext;
use MyParcelNL\Magento\Ui\Component\Listing\Column\TrackAndTrace;

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
     * Send the shipment email with the track & trace link.
     *
     * One failed send does not stop the loop. With `sales_email/general/async_sending` off, a large
     * batch pays one SMTP round trip per shipment before the label PDF reaches the admin.
     * A shipment without a real barcode gets no mail yet: email_sent would block the mail that has the link.
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

        $tracks = $this->tracksByShipmentId();

        foreach ($this->shipments as $shipment) {
            if ($shipment->getEmailSent() != null || ! self::hasRealBarcode($tracks[(int) $shipment->getId()] ?? [])) {
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

    /** @param \Magento\Sales\Model\Order\Shipment\Track[] $tracks */
    private static function hasRealBarcode(array $tracks): bool
    {
        foreach ($tracks as $track) {
            if (TrackAndTrace::isRealBarcode((string) $track->getTrackNumber())) {
                return true;
            }
        }

        return false;
    }
}
