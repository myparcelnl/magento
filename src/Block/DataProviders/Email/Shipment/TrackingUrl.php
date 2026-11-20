<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\TrackTrace\LinkResolver;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;

/**
 * Shared by the two conditional declarations below, which cannot be collapsed because one extends
 * a Magento class that does not exist before 2.3.2.
 */
trait BuildsTrackingUrl
{
    /**
     * The resolver is looked up, not injected: the Magento parent below takes a ShippingHelper, and
     * the else-branch has no parent to hand one to, so this trait cannot declare a constructor.
     */
    public function getUrl(Track $track): string
    {
        // The email lists every carrier's tracks; a MyParcel portal link for another carrier's barcode is wrong.
        if (! MyParcelTracks::isOwn($track)) {
            return $this->foreignUrl($track);
        }

        return ObjectManager::getInstance()
            ->get(LinkResolver::class)
            ->forTrack($track);
    }
}

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
