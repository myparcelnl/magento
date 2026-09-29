<?php

namespace MyParcelNL\Magento\Block\Sales;

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;

/**
 * Human labels for new_shipment.phtml.
 *
 * Labels only. Which carriers, package types and options a form offers is account data and comes
 * from {@see NewShipment}'s capability lookup, not from here.
 */
class NewShipmentForm
{
    public const PACKAGE_TYPE_HUMAN_MAP = [
        PackageType::PACKAGE       => 'Package',
        PackageType::MAILBOX       => 'Mailbox',
        PackageType::LETTER        => 'Letter',
        PackageType::DIGITAL_STAMP => 'Digital stamp',
        PackageType::PACKAGE_SMALL => 'Small package',
    ];

    /** The option's translated label. */
    public function labelFor(string $option): string
    {
        return (string) __(ShipmentOption::labelFor($option));
    }
}
