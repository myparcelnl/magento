<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Shipment\Type;

use MyParcelNL\Magento\Model\Shipment\PackageType;

/** A stored package type. See AbstractTypeValue. */
final class PackageTypeValue extends AbstractTypeValue
{
    protected static function nameForId(int $id): ?string
    {
        return PackageType::nameFromIdOrNull($id);
    }

    protected static function idForName(string $name): ?int
    {
        return PackageType::toIdOrNull($name);
    }

    protected static function typeLabel(): string
    {
        return 'Package type';
    }
}
