<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Adapter;

use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;

class DeliveryOptionsFromOrderAdapter extends AbstractDeliveryOptionsAdapter
{
    /**
     * @param array $inputData
     */
    public function __construct(array $inputData = [])
    {
        $this->carrier         = $inputData['carrier'] ?? null;
        $this->date            = $originAdapter ?? null;
        $this->deliveryType    = $inputData['deliveryType'] ?? null;
        $this->packageType     = $inputData['packageType'] ?? null;
        $this->shipmentOptions = new ShipmentOptionsFromAdapter($inputData);
        $this->pickupLocation  = $originAdapter ?? null;
    }
}
