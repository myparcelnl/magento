<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Request;

use MyParcelNL\Magento\Model\Rest\Transformer\CarrierTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\DateTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\DeliveryTypeTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\PackageTypeTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\PickupLocationTransformer;
use MyParcelNL\Magento\Model\Rest\Transformer\ShipmentOptionsTransformer;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;

class OrderDeliveryOptionsV1Request
{
    private CarrierTransformer $carrierTransformer;
    private PackageTypeTransformer $packageTypeTransformer;
    private DeliveryTypeTransformer $deliveryTypeTransformer;
    private ShipmentOptionsTransformer $shipmentOptionsTransformer;
    private DateTransformer $dateTransformer;
    private PickupLocationTransformer $pickupLocationTransformer;

    public function __construct(
        CarrierTransformer $carrierTransformer,
        PackageTypeTransformer $packageTypeTransformer,
        DeliveryTypeTransformer $deliveryTypeTransformer,
        ShipmentOptionsTransformer $shipmentOptionsTransformer,
        DateTransformer $dateTransformer,
        PickupLocationTransformer $pickupLocationTransformer
    ) {
        $this->carrierTransformer = $carrierTransformer;
        $this->packageTypeTransformer = $packageTypeTransformer;
        $this->deliveryTypeTransformer = $deliveryTypeTransformer;
        $this->shipmentOptionsTransformer = $shipmentOptionsTransformer;
        $this->dateTransformer = $dateTransformer;
        $this->pickupLocationTransformer = $pickupLocationTransformer;
    }

    public function transform(AbstractDeliveryOptionsAdapter $adapter): array
    {
        return [
            'carrier'         => $this->carrierTransformer->transform($adapter->getCarrier()),
            'packageType'     => $this->packageTypeTransformer->transform($adapter->getPackageType()),
            'deliveryType'    => $this->deliveryTypeTransformer->transform($adapter->getDeliveryType()),
            'shipmentOptions' => $this->shipmentOptionsTransformer->transform($adapter->getShipmentOptions()),
            'date'            => $this->dateTransformer->transform($adapter->getDate()),
            'pickupLocation'  => $this->pickupLocationTransformer->transform($adapter->getPickupLocation()),
        ];
    }
}
