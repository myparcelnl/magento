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

    /**
     * @var array
     */
    private array $shipmentOptionsHumanMap;

    public function __construct()
    {
        $this->shipmentOptionsHumanMap = [
            ShipmentOption::SIGNATURE          => __('Signature on receipt'),
            ShipmentOption::RECEIPT_CODE       => __('Receipt code'),
            ShipmentOption::COLLECT            => __('Collect package'),
            ShipmentOption::ONLY_RECIPIENT     => __('Only recipient'),
            ShipmentOption::AGE_CHECK          => __('Age check 18+'),
            ShipmentOption::HIDE_SENDER        => __('Hide sender'),
            ShipmentOption::LARGE_FORMAT       => __('Large package'),
            ShipmentOption::RETURN             => __('Return if no answer'),
            ShipmentOption::SAME_DAY_DELIVERY  => __('Same day delivery'),
            ShipmentOption::PRINTERLESS_RETURN => __('Printerless return'),
            ShipmentOption::FRESH_FOOD         => __('Fresh food'),
            ShipmentOption::FROZEN             => __('Frozen'),
            ShipmentOption::PRIORITY_DELIVERY  => __('Priority delivery'),
        ];
    }

    /**
     * @return array
     */
    public function getShipmentOptionsHumanMap(): array
    {
        return $this->shipmentOptionsHumanMap;
    }
}
