<?php

namespace MyParcelNL\Magento\Block\Sales;

use Exception;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLEuroplus;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLForYou;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDHLParcelConnect;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierDPD;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierFactory;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierPostNL;
use MyParcelNL\Sdk\src\Model\Carrier\CarrierUPS;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;

class NewShipmentForm
{
    public const ALLOWED_CARRIER_CLASSES
        = [
            CarrierPostNL::class,
            CarrierDHLForYou::class,
            CarrierDHLEuroplus::class,
            CarrierDHLParcelConnect::class,
            CarrierUPS::class,
            CarrierDPD::class,
        ];

    public const PACKAGE_TYPE_HUMAN_MAP
        = [
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

    /**
     * @var array
     */
    private $shipmentOptionsExplanationMap;


    public function __construct()
    {
        $this->shipmentOptionsHumanMap    = [
            AbstractConsignment::SHIPMENT_OPTION_SIGNATURE         => __('Signature on receipt'),
            AbstractConsignment::SHIPMENT_OPTION_COLLECT           => __('Collect package'),
            AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE      => __('Receiving code'),
            AbstractConsignment::SHIPMENT_OPTION_ONLY_RECIPIENT    => __('Home address only'),
            AbstractConsignment::SHIPMENT_OPTION_AGE_CHECK         => __('Age check 18+'),
            AbstractConsignment::SHIPMENT_OPTION_HIDE_SENDER       => __('Hide sender'),
            AbstractConsignment::SHIPMENT_OPTION_LARGE_FORMAT      => __('Large package'),
            AbstractConsignment::SHIPMENT_OPTION_RETURN            => __('Return if no answer'),
            AbstractConsignment::SHIPMENT_OPTION_SAME_DAY_DELIVERY => __('Same day delivery'),
        ];
        $this->shipmentOptionsExplanationMap = [
            AbstractConsignment::SHIPMENT_OPTION_RECEIPT_CODE => __('Insurance is mandatory and will be set. Other shipment options will be removed.'),
            AbstractConsignment::SHIPMENT_OPTION_COLLECT      => __('The package will be collected at the location for this webshop. Please make sure you completely filled in the address including the company name in the MyParcel Backoffice. You can do so under ‘Shop settings’ -> ‘Company details’ under the tab ‘Return address’. Verify that you selected the shop corresponding to the API key you are using in your Magento installation.'),
        ];
    }

    /**
     * @return AbstractConsignment[]
     * @throws Exception
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

    /**
     * @return array
     */
    public function getShipmentOptionsExplanationMap(): array
    {
        return $this->shipmentOptionsExplanationMap;
    }
}
