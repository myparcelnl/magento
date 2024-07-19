<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Adapter;

use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;

class ShipmentOptionsFromAdapter extends AbstractShipmentOptionsAdapter
{
    const DEFAULT_INSURANCE = 0;

    /**
     * @param array $inputData
     */
    public function __construct(array $inputData)
    {
        $options              = $inputData ?? [];
        $this->signature      = (bool) ($options['signature'] ?? false);
        $this->only_recipient = (bool) ($options['only_recipient'] ?? false);
        $this->large_format   = (bool) ($options['large_format'] ?? false);
        $this->age_check      = (bool) ($options['age_check'] ?? false);
        $this->return         = (bool) ($options['return'] ?? false);
        $this->insurance      = (int) ($options['insurance'] ?? self::DEFAULT_INSURANCE);
    }
}
