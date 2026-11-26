<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Settings\Blueprint;

use MyParcelNL\Magento\Model\Shipment\Capabilities\CapabilitySet;
use MyParcelNL\Magento\Model\Shipment\Carrier;

/**
 * Builds the settings form for one account from its capabilities.
 *
 * Pure: it reads nothing but its arguments, so a test can build every scope's form without Magento.
 * The module lists no carrier, so a set that could not be read gets no carrier section at all and
 * the form says why.
 */
final class Generator
{
    /**
     * @param string[] $internationalMailbox carriers the account may send mailbox parcels abroad with
     */
    public static function for(CapabilitySet $capabilities, array $internationalMailbox = []): Blueprint
    {
        $sections = [];
        $facts    = [];

        foreach ($capabilities->carriers() as $carrier) {
            $shape = CarrierShape::fromCapabilities($capabilities, $carrier);
            array_push($facts, ...$shape->facts());
            $sections[] = Catalogue::carrierSection(
                $carrier,
                $shape,
                Carrier::isExportable($carrier),
                in_array($carrier, $internationalMailbox, true)
            );
        }

        $permissive = $capabilities->isPermissive();

        array_unshift($sections, Catalogue::generalSection($permissive ? null : array_unique($facts)));

        return new Blueprint($sections, $permissive);
    }
}
