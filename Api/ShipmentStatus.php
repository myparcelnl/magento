<?php

declare(strict_types=1);

namespace MyParcelBE\Magento\Api;

class ShipmentStatus
{
    public const CANCELLED       = 17;
    public const CREDITED        = 13;
    public const CONCEPT         = 1;
    public const REGISTERED      = 2;
    public const PRINTED_MINIMUM = self::REGISTERED;
}
