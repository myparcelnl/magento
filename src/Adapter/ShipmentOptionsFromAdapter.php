<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Adapter;

use MyParcelNL\Magento\Helper\ShipmentOptions;
use MyParcelNL\Sdk\Adapter\DeliveryOptions\AbstractShipmentOptionsAdapter;

class ShipmentOptionsFromAdapter extends AbstractShipmentOptionsAdapter
{
    const DEFAULT_INSURANCE = 0;

    /**
     * @param array $inputData
     */
    public function __construct(array $inputData)
    {
        $options                 = $inputData ?? [];
        $this->signature         = (bool) ($options[ShipmentOptions::SIGNATURE] ?? false);
        $this->collect           = (bool) ($options[ShipmentOptions::COLLECT] ?? false);
        $this->receipt_code      = (bool) ($options[ShipmentOptions::RECEIPT_CODE] ?? false);
        $this->only_recipient    = (bool) ($options[ShipmentOptions::ONLY_RECIPIENT] ?? false);
        $this->large_format      = (bool) ($options[ShipmentOptions::LARGE_FORMAT] ?? false);
        $this->age_check         = (bool) ($options[ShipmentOptions::AGE_CHECK] ?? false);
        $this->return            = (bool) ($options[ShipmentOptions::RETURN] ?? false);
        $this->priority_delivery = (bool) ($options[ShipmentOptions::PRIORITY_DELIVERY] ?? false);
        $this->insurance         = (int) ($options[ShipmentOptions::INSURANCE] ?? self::DEFAULT_INSURANCE);
    }
}
