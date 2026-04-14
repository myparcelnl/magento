<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\DeliveryType as OrderApiDeliveryType;
use MyParcelNL\Sdk\Support\Str;

class DeliveryTypeTransformer
{
    private const ORDER_API_SUFFIX = '_DELIVERY';

    public function transform(?string $deliveryType): ?string
    {
        if ($deliveryType === null) {
            return null;
        }

        $allowed = OrderApiDeliveryType::getAllowableEnumValues();

        // 1. Direct match against the generated Order API enum.
        if (in_array($deliveryType, $allowed, true)) {
            return $deliveryType;
        }

        // 2. SCREAMING_SNAKE_CASE conversion.
        $converted = Str::upper(Str::snake($deliveryType));
        if (in_array($converted, $allowed, true)) {
            return $converted;
        }

        // 3. Magento passes short aliases ("standard", "morning", …); all Order API
        //    delivery-type values share the _DELIVERY suffix, so append and re-check.
        $suffixed = $converted . self::ORDER_API_SUFFIX;
        if (in_array($suffixed, $allowed, true)) {
            return $suffixed;
        }

        return null;
    }
}
