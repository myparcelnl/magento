<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Sales\Model\Order\Shipment\Track;

// For Magento version < 2.3.2 the TrackingUrl does not exist. Therefore, it must be checked if the class exists and so that the class can be extended.
if (class_exists('\Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl')) {

    class TrackingUrl extends \Magento\Sales\Block\DataProviders\Email\Shipment\TrackingUrl
    {
        use BuildsTrackingUrl;

        /** Magento's own popup link, as the other carriers get without this module. */
        protected function foreignUrl(Track $track): string
        {
            return parent::getUrl($track);
        }
    }

} else {

    class TrackingUrl
    {
        use BuildsTrackingUrl;

        /** No Magento provider to delegate to before 2.3.2. */
        protected function foreignUrl(Track $track): string
        {
            return '';
        }
    }
}
