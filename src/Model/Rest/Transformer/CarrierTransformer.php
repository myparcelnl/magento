<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\Carrier as OrderApiCarrier;

class CarrierTransformer extends AbstractEnumTransformer
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

    protected function getAllowableValues(): array
    {
        return OrderApiCarrier::getAllowableEnumValues();
    }

    protected function resolveFallback(string $original, string $converted, array $allowed): ?string
    {
        return self::LEGACY_NAME_MAP[$original] ?? null;
    }
}
