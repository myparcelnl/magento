<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\DeliveryType as OrderApiDeliveryType;

class DeliveryTypeTransformer extends AbstractEnumTransformer
{
    private const ORDER_API_SUFFIX = '_DELIVERY';

    protected function getAllowableValues(): array
    {
        return OrderApiDeliveryType::getAllowableEnumValues();
    }

    protected function resolveFallback(string $original, string $converted, array $allowed): ?string
    {
        $suffixed = $converted . self::ORDER_API_SUFFIX;
        if (in_array($suffixed, $allowed, true)) {
            return $suffixed;
        }

        return null;
    }
}
