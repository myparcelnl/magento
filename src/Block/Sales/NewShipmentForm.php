<?php

namespace MyParcelNL\Magento\Block\Sales;

use Exception;
use MyParcelNL\Magento\Model\Carrier\Carrier;
use MyParcelNL\Sdk\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\Model\Consignment\AbstractConsignment;

class NewShipmentForm
{
    public const PACKAGE_TYPE_HUMAN_MAP = [
        AbstractConsignment::PACKAGE_TYPE_PACKAGE       => 'Package',
        AbstractConsignment::PACKAGE_TYPE_MAILBOX       => 'Mailbox',
        AbstractConsignment::PACKAGE_TYPE_LETTER        => 'Letter',
        AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP => 'Digital stamp',
        AbstractConsignment::PACKAGE_TYPE_PACKAGE_SMALL => 'Small package',
    ];

    /**
     * @var array
     */
    private array $shipmentOptionsHumanMap;

    public function __construct()
    {
        $this->shipmentOptionsHumanMap = [
            AbstractConsignment::SHIPMENT_OPTION_SIGNATURE          => __('Signature on receipt'),
            AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE       => __('Receipt code'),
            AbstractConsignment::SHIPMENT_OPTION_COLLECT            => __('Collect package'),
            AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT     => __('Home address only'),
            AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK          => __('Age check 18+'),
            AbstractConsignment::SHIPMENT_OPTION_HIDE_SENDER        => __('Hide sender'),
            AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT       => __('Large package'),
            AbstractConsignment::SHIPMENT_OPTION_RETURN             => __('Return if no answer'),
            AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY  => __('Same day delivery'),
            AbstractConsignment::SHIPMENT_OPTION_PRINTERLESS_RETURN => __('Printerless return'),
            AbstractConsignment::SHIPMENT_OPTION_FRESH_FOOD         => __('Fresh food'),
            AbstractConsignment::SHIPMENT_OPTION_FROZEN             => __('Frozen'),
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
