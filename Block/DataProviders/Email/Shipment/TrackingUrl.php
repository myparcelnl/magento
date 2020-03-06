<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl as MagentoTrackingUrl;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyparcelNL\Sdk\src\Helper\TrackTraceUrl;

/**
 * Shipment track info for email
 */
class TrackingUrl extends MagentoTrackingUrl implements ArgumentInterface
{
    /**
     * Get full Track & Trace url for the shipping e-mail
     *
     * @param Track $track
     *
     * @return string
     */
    public function getUrl(Track $track): string
    {
        /**
         * @var Order
         */
        $order = (ObjectManager::getInstance())->create('\Magento\Sales\Model\Order')->load($track->getOrderId());

        return (new TrackTraceUrl())->create(
            $track->getNumber(),
            $order->getShippingAddress()->getPostcode(),
            $order->getShippingAddress()->getCountryId()
        );
    }
}
