<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;

it('titles only package types the module knows', function () {
    expect(array_diff(array_keys(PackageType::TITLES), array_keys(PackageType::NAMES_IDS_MAP)))->toBe([]);
});
