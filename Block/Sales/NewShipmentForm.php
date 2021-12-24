<?php

namespace MyParcelNL\Magento\Block\Sales;

use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierRedJePakketje;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class NewShipmentForm
{
    public const ALLOWED_CARRIER_CLASSES = [
        CarrierPostNL::class,
        CarrierRedJePakketje::class,
    ];

    public const PACKAGE_TYPE_HUMAN_MAP = [
        AbstractConsignment::PACKAGE_TYPE_PACKAGE       => 'Package',
        AbstractConsignment::PACKAGE_TYPE_MAILBOX       => 'Mailbox',
        AbstractConsignment::PACKAGE_TYPE_LETTER        => 'Letter',
        AbstractConsignment::PACKAGE_TYPE_DIGITAL_STAMP => 'Digital stamp',
    ];

    /**
     * @var array
     */
    private $shipmentOptionsHumanMap;

    /**
     * @return AbstractConsignment[]
     * @throws \Exception
     */
    public function getCarrierSpecificAbstractConsignments(): array
    {
        $returnArray = [];

        foreach (self::ALLOWED_CARRIER_CLASSES as $carrier) {
            $returnArray[] = ConsignmentFactory::createFromCarrier(CarrierFactory::create($carrier));
        }

        return $returnArray;
    }

    /**
     * @param array $map
     */
    public function setShipmentOptionsHumanMap(array $map): void
    {
        $this->shipmentOptionsHumanMap = $map;
    }

    /**
     * @return array
     */
    public function getShipmentOptionsHumanMap(): array
    {
        return $this->shipmentOptionsHumanMap;
    }
}
