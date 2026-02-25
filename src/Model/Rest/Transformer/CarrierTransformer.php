<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

class CarrierTransformer
{
    private const MAP = [
        'postnl'           => 'POSTNL',
        'bpost'            => 'BPOST',
        'dhlforyou'        => 'DHL_FOR_YOU',
        'dhlparcelconnect' => 'DHL_PARCEL_CONNECT',
        'dhleuroplus'      => 'DHL_EUROPLUS',
        'ups'              => 'UPS_STANDARD',
        'bol.com'          => 'BOL',
        'bol_com'          => 'BOL',
        'cheap_cargo'      => 'CHEAP_CARGO',
        'dpd'              => 'DPD',
        'gls'              => 'GLS',
        'brt'              => 'BRT',
        'trunkrs'          => 'TRUNKRS',
    ];

    public function transform(?string $carrier): ?string
    {
        if ($carrier === null) {
            return null;
        }

        if (isset(self::MAP[$carrier])) {
            return self::MAP[$carrier];
        }

        return strtoupper(str_replace(['.', '-'], '_', $carrier));
    }
}
