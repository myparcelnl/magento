<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\PackageType as OrderApiPackageType;

class PackageTypeTransformer extends AbstractEnumTransformer
{
    /**
     * Magento package-type aliases that do not normalise to an Order API
     * enum value via SCREAMING_SNAKE_CASE conversion alone.
     */
    private const LEGACY_NAME_MAP = [
        'letter'        => OrderApiPackageType::UNFRANKED,
        'package_small' => OrderApiPackageType::SMALL_PACKAGE,
    ];

    protected function getAllowableValues(): array
    {
        return OrderApiPackageType::getAllowableEnumValues();
    }

    protected function resolveFallback(string $original, string $converted, array $allowed): ?string
    {
        return self::LEGACY_NAME_MAP[$original] ?? null;
    }
}
