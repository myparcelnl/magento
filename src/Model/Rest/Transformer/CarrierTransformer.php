<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\Carrier as OrderApiCarrier;
use MyParcelNL\Sdk\Support\Str;

class CarrierTransformer
{
    /**
     * Legacy Magento carrier names that do not normalise to an Order API
     * enum value via SCREAMING_SNAKE_CASE conversion alone.
     */
    private const LEGACY_NAME_MAP = [
        'bol.com'          => OrderApiCarrier::BOL,
        'bol_com'          => OrderApiCarrier::BOL,
        'dhlforyou'        => OrderApiCarrier::DHL_FOR_YOU,
        'dhlparcelconnect' => OrderApiCarrier::DHL_PARCEL_CONNECT,
        'dhleuroplus'      => OrderApiCarrier::DHL_EUROPLUS,
        'ups'              => OrderApiCarrier::UPS_STANDARD,
    ];

    public function transform(?string $carrier): ?string
    {
        if ($carrier === null) {
            return null;
        }

        $allowed = OrderApiCarrier::getAllowableEnumValues();

        // 1. Direct match against the generated Order API enum.
        if (in_array($carrier, $allowed, true)) {
            return $carrier;
        }

        // 2. SCREAMING_SNAKE_CASE conversion, then re-check.
        $converted = Str::upper(Str::snake($carrier));
        if (in_array($converted, $allowed, true)) {
            return $converted;
        }

        // 3. Curated fallback for Magento-only legacy aliases.
        return self::LEGACY_NAME_MAP[$carrier] ?? null;
    }
}
