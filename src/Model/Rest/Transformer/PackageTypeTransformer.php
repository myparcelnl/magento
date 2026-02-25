<?php

declare(strict_types=1);

namespace MyParcelNL\Magento\Model\Rest\Transformer;

class PackageTypeTransformer
{
    private const MAP = [
        'package'       => 'PACKAGE',
        'mailbox'       => 'MAILBOX',
        'letter'        => 'UNFRANKED',
        'digital_stamp' => 'DIGITAL_STAMP',
        'package_small' => 'SMALL_PACKAGE',
    ];

    public function transform(?string $packageType): ?string
    {
        if ($packageType === null) {
            return null;
        }

        return self::MAP[$packageType] ?? strtoupper($packageType);
    }
}
