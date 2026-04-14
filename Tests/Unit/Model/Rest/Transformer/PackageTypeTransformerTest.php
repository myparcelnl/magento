<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Rest\Transformer\PackageTypeTransformer;
use MyParcelNL\Sdk\Client\Generated\OrderApi\Model\PackageType as OrderApiPackageType;

it('returns null for null input', function () {
    expect((new PackageTypeTransformer())->transform(null))->toBeNull();
});

it('returns an Order API enum value unchanged', function () {
    expect((new PackageTypeTransformer())->transform(OrderApiPackageType::PACKAGE))
        ->toBe(OrderApiPackageType::PACKAGE);
});

it('converts lowercase input to the matching Order API enum via snake_case', function () {
    expect((new PackageTypeTransformer())->transform('package'))->toBe(OrderApiPackageType::PACKAGE);
    expect((new PackageTypeTransformer())->transform('mailbox'))->toBe(OrderApiPackageType::MAILBOX);
    expect((new PackageTypeTransformer())->transform('digital_stamp'))->toBe(OrderApiPackageType::DIGITAL_STAMP);
});

it('maps legacy Magento package type aliases to the Order API enum', function () {
    $t = new PackageTypeTransformer();

    expect($t->transform('letter'))->toBe(OrderApiPackageType::UNFRANKED);
    expect($t->transform('package_small'))->toBe(OrderApiPackageType::SMALL_PACKAGE);
});

it('returns null for unknown package types', function () {
    expect((new PackageTypeTransformer())->transform('does_not_exist'))->toBeNull();
});
