<?php
/**
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Model\Sales;

use Magento\Framework\Exception\LocalizedException;
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
     *
     * @todo; add filter carrier code
     */
    public function setMyParcelTrack()
    {
        /**
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $magentoTrack
         */
        foreach ($this->shipments as $shipment) {
            foreach ($this->getTrackByShipment($shipment)->getItems() as $magentoTrack) {
                if ($magentoTrack->getCarrierCode() == MyParcelTrackTrace::MYPARCEL_CARRIER_CODE) {
                    $this->myParcelCollection->addConsignment($this->getMyParcelTrack($magentoTrack));
                }
            }
        }

        return $this;
    }

    /**
     * Set PDF content and convert status 'Concept' to 'Registered'
     *
     * @return $this
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
     * Create MyParcel concepts and update Magento Track
     *
     * @return $this
     */
    public function createMyParcelConcepts()
    {
        $this->myParcelCollection->createConcepts()->setLatestData();

        /**
         * @var Order                $order
         * @var Order\Shipment       $shipment
         * @var Order\Shipment\Track $track
         */
        foreach ($this->getShipments() as $shipment) {
            foreach ($shipment->getTracksCollection() as $track) {
                $myParcelTrack = $this
                    ->myParcelCollection->getConsignmentByReferenceId($track->getId());

                $track
                    ->setData('myparcel_consignment_id', $myParcelTrack->getMyParcelConsignmentId())
                    ->setData('myparcel_status', $myParcelTrack->getStatus())
                    ->save(); // must
            }
        }

        return $this;
    }

    /**
     * Update MyParcel collection
     *
     * @return $this
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
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateMagentoTrack()
    {
        /**
         * @var $order        Order
         * @var $shipment     Order\Shipment
         * @var $magentoTrack Order\Shipment\Track
         */
        foreach ($this->getShipments() as $shipment) {

            $trackCollection = $shipment->getAllTracks();
            foreach ($trackCollection as $magentoTrack) {
                $myParcelTrack = $this->myParcelCollection->getConsignmentByApiId(
                    $magentoTrack->getData('myparcel_consignment_id')
                );

                $magentoTrack->setData('myparcel_status', $myParcelTrack->getStatus());

                if ($myParcelTrack->getBarcode()) {
                    $magentoTrack->setTrackNumber($myParcelTrack->getBarcode());
                }
                $magentoTrack->save();
            }
        }

        $this->updateGridByShipment();

        return $this;
    }

    /**
     * Update column track_status in sales_order_grid
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateGridByShipment()
    {
        if (empty($this->getShipments())) {
            throw new LocalizedException(__('MagentoOrderCollection::shipment array is empty'));
        }

        /**
         * @var \Magento\Sales\Model\ResourceModel\Order\Shipment\Collection $shipment
         * @var Order $order
         */
        foreach ($this->getShipments() as $shipment) {

            $order = $shipment->getOrder();
            $aHtml = $this->getHtmlForGridColumns($order->getId());

            if ($aHtml['track_status']) {
                $order->setData('track_status', $aHtml['track_status']);
            }
            if ($aHtml['track_number']) {
                $order->setData('track_number', $aHtml['track_number']);
            }
            $order->save();
        }

        return $this;
    }
}
