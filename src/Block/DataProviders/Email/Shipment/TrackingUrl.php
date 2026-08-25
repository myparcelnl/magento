<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\TrackTraceUrl;

/**
 * Shared by the two conditional declarations below, which cannot be collapsed because one extends
 * a Magento class that does not exist before 2.3.2.
 */
trait BuildsTrackingUrl
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
        /** @var Order $order */
        $order = (ObjectManager::getInstance())->create(Order::class)->load($track->getOrderId());

        return TrackTraceUrl::create(
            $track->getNumber(),
            $order->getShippingAddress()->getPostcode(),
            $order->getShippingAddress()->getCountryId()
        );
    }
}

// For Magento version < 2.3.2 the TrackingUrl does not exist. Therefore, it must be checked if the class exists and so that the class can be extended.
if (class_exists('\Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl')) {

    /**
     * Shipment track info for email
     */
    class TrackingUrl extends \Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl
    {
        use BuildsTrackingUrl;
    }

} else {

    /**
     * Shipment track info for email
     */
    class TrackingUrl
    {
        use BuildsTrackingUrl;
    }
}
