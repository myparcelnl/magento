<?php

namespace MyParcelNL\Magento\Block\DataProviders\Email\Shipment;

use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order\Shipment\Track;
use MyParcelNL\Magento\Service\TrackTrace\LinkResolver;
use MyParcelNL\Magento\Service\TrackTrace\MyParcelTracks;

/**
 * Shared by the two conditional TrackingUrl declarations, which cannot be collapsed because one extends
 * a Magento class that does not exist before 2.3.2.
 */
trait BuildsTrackingUrl
{
    /**
     * Get the track & trace URL for the shipment email.
     *
     * The resolver is looked up, not injected: the Magento parent of TrackingUrl takes a
     * ShippingHelper, and the else-branch has no parent to hand one to, so this trait cannot declare
     * a constructor.
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
