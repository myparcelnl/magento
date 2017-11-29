<?php
/**
 * Block for order actions (multiple orders action and one order action)
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <reindert@myparcel.nl>
 * @copyright   2010-2017 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release v0.1.0
 */

namespace MyParcelNL\Magento\Block\Sales;

use Magento\Backend\Block\Template\Context;
use MyParcelNL\Sdk\src\Model\MyParcelClassConstants;

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
     * @param Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @param array $data
     */
    public function __construct(
        Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Sales\Model\Order\Shipment $shipment,
        array $data = []
    ) {
        // Set shipment and order
        $aShipment = $registry->registry('current_shipment');
        $this->shipment = $shipment->load($aShipment['entity_id']);

        $this->order = $this->shipment->getOrder();
        parent::__construct($context, $data);
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
     * @throws \Magento\Framework\Exception\LocalizedException
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
        return !in_array(
            $this->getCountry(),
            MyParcelClassConstants::EU_COUNTRIES
        );
    }
}
