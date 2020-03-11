<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl as MagentoTrackingUrl;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyparcelNL\Sdk\src\Helper\TrackTraceUrl;

/**
 * Shipment track info for email
 */
class TrackingUrl extends MagentoTrackingUrl
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
        $order = (ObjectManager::getInstance())->create(Order::class)->load($track->getOrderId());

        return (new TrackTraceUrl())->create(
            $track->getNumber(),
            $order->getShippingAddress()->getPostcode(),
            $order->getShippingAddress()->getCountryId()
        );
    }
}
