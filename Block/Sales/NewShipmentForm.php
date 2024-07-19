<?php

namespace MyParcelBE\Magento\Block\Sales;

use MyParcelBE\Magento\Model\Checkout\Carrier;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierUPS;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierBpost;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class NewShipmentForm
{
    public const ALLOWED_CARRIER_CLASSES = [
        CarrierPostNL::class,
        CarrierDHLForYou::class,
        CarrierDHLEuroplus::class,
        CarrierDHLParcelConnect::class,
        CarrierUPS::class,
        CarrierDPD::class,
        CarrierBpost::class,
    ];

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
    private $shipmentOptionsHumanMap;


    public function __construct()
    {
        $this->shipmentOptionsHumanMap = [
            AbstractConsignment::SHIPMENT_OPTION_SIGNATURE         => __('Signature on receipt'),
            AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT    => __('Home address only'),
            AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK         => __('Age check 18+'),
            AbstractConsignment::SHIPMENT_OPTION_HIDE_SENDER       => __('Hide sender'),
            AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT      => __('Large package'),
            AbstractConsignment::SHIPMENT_OPTION_RETURN            => __('Return if no answer'),
            AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY => __('Same day delivery'),
        ];
    }
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
     * @return array
     */
    public function getShipmentOptionsHumanMap(): array
    {
        return $this->shipmentOptionsHumanMap;
    }
}
