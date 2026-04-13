<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\PackageType as OrderApiPackageType;
use MyParcelNL\Sdk\Support\Str;

class PackageTypeTransformer
{
    /**
     * Magento package-type aliases that do not normalise to an Order API
     * enum value via SCREAMING_SNAKE_CASE conversion alone.
     */
    private const LEGACY_NAME_MAP = [
        'letter'        => OrderApiPackageType::UNFRANKED,
        'package_small' => OrderApiPackageType::SMALL_PACKAGE,
    ];

    public function transform(?string $packageType): ?string
    {
        if ($packageType === null) {
            return null;
        }

        $allowed = OrderApiPackageType::getAllowableEnumValues();

        if (in_array($packageType, $allowed, true)) {
            return $packageType;
        }

        $converted = Str::upper(Str::snake($packageType));
        if (in_array($converted, $allowed, true)) {
            return $converted;
        }

        return self::LEGACY_NAME_MAP[$packageType] ?? null;
    }
}
