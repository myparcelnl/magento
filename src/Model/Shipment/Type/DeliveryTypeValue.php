<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Type;

use MyParcelNL\Magento\Model\Shipment\DeliveryType;

/** A stored delivery type. See AbstractTypeValue. */
final class DeliveryTypeValue extends AbstractTypeValue
{
    protected static function nameForId(int $id): ?string
    {
        return DeliveryType::nameFromIdOrNull($id);
    }

    protected static function idForName(string $name): ?int
    {
        return DeliveryType::toIdOrNull($name);
    }

    protected static function typeLabel(): string
    {
        return 'Delivery type';
    }
}
