<?php
/**
 * Block for order actions (multiple orders action and one order action)
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelbe
 *
 * @author      Reindert Vetter <info@sendmyparcel.be>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelbe/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelBE\Magento\Block\Sales;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\Shipment;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class ShipmentAction extends OrdersAction
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;
    /**
     * @var \Magento\Sales\Model\Order\Shipment
     */
    private $shipment;
    /**
     * @var \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment
     */
    private $consignment;

    /**
     * @param Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @param \MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment $consignment
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        Shipment $shipment,
        AbstractConsignment $consignment,
        array $data = []
    ) {
        // Set shipment and order
        $aShipment = $registry->registry('current_shipment');
        $this->shipment = $shipment->load($aShipment['entity_id']);

        $this->order = $this->shipment->getOrder();
        parent::__construct($context, $data);
        $this->consignment = $consignment;
    }

    public function getEntityId()
    {
        return $this->shipment['entity_id'];
    }

    /**
     * Check if Magento can create shipment
     *
     * Magento shipment contains one or more products. Magento shipments can never make more shipments than the number
     * of products.
     *
     * @return bool
     */
    public function hasTrack()
    {
        return count($this->shipment->getAllTracks()) > 0 ? true : false;
    }

    /**
     * Get number of print positions. Always more than one
     */
    public function getNumberOfPrintPositions()
    {
        $numberOfTracks = count($this->shipment->getAllTracks());
        return $numberOfTracks > 0 ? $numberOfTracks : 1;
    }

    /**
     * @return string
     */
    public function getCountry()
    {
        return $this->order->getShippingAddress()->getCountryId();
    }

    /**
     * Check if the address is outside the EU
     * @return bool
     */
    public function isCdCountry()
    {
        return $this->consignment->isCdCountry();
    }
}
