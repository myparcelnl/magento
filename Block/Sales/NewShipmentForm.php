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

    public function __construct()
    {
    }

    public function doIt()
    {
        foreach (self::ALLOWED_CARRIER_CLASSES as $carrier) {
            try {
                $this->writeOutCarrier(ConsignmentFactory::createFromCarrier(CarrierFactory::create($carrier)));
            } catch (\Exception $e) {
                $this->writeMessage($e->getMessage());
            }
        }
    }

    private function writeOutCarrier(AbstractConsignment $consignment)
    {
        echo '<h1>' . $consignment->getCarrierName() . '</h1>';
        echo $consignment->getAllowedPackageTypes();
//        $consignment->getAllowedExtraOptions();
//        $consignment->getInsurancePossibilities();
//        $consignment->getAllowedDeliveryTypes();
//        $consignment->getAllowedShipmentOptions();
    }

    private function writeMessage(string $message)
    {
        echo '<strong>' . $message . '</strong>';
    }
}
