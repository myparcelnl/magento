<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Service;

use MyParcelNL\Magento\Model\Settings\Proposition;

/**
 * The fallback track & trace URL, for a shipment stored before the module read the link from the API.
 *
 * The host is the proposition's own: a Belgian account's consumer portal is not myparcel.me.
 */
class TrackTraceUrl
{
    /** Null without a proposition, or for one that has no consumer portal host: no link beats a wrong host. */
    public function create(
        ?Proposition $proposition,
        string       $barcode,
        string       $postalCode,
        ?string      $countryCode = null
    ): ?string
    {
        $baseUrl = $proposition ? $proposition->getTrackTraceUrl() : null;

        if (null === $baseUrl) {
            return null;
        }

        // Every part is a path segment, so it is encoded: a barcode or postcode carrying a slash or
        // a quote would otherwise change the URL the admin clicks. Spaces go before the encoding,
        // or they survive as %20.
        $url = $baseUrl
            . rawurlencode($barcode)
            . '/'
            . rawurlencode(str_replace(' ', '', $postalCode));

        if ($countryCode) {
            $url .= '/' . rawurlencode($countryCode);
        }

        return $url;
    }
}
