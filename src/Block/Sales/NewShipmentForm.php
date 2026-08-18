<?php

namespace MyParcelNL\Magento\Block\Sales;

use Exception;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\ShipmentOption;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

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
     * @return AbstractConsignment[]
     * @throws Exception
     */
    public function getCarrierSpecificAbstractConsignments(): array
    {
        $returnArray = [];

        foreach (Carrier::ALLOWED_CARRIER_CLASSES as $carrier) {
            $returnArray[] = ConsignmentFactory::createFromCarrier(CarrierFactory::create($carrier));
        }

        return $returnArray;
    }

    /**
     * @return array
     */
    public function getShipmentOptionsHumanMap(): array
    {
        return $this->shipmentOptionsHumanMap;
    }
}
