<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Sdk\src\Helper\TrackTraceUrl;

// For Magento version < 2.3.2
if (class_exists('\Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl')) {

    /**
     * Shipment track info for email
     */
    class TrackingUrl extends \Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl
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

} else {

    /**
     * Shipment track info for email
     */
    class TrackingUrl
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
}
