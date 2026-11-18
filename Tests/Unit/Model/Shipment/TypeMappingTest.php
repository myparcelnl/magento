<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;

it('maps all seven package types and delivery types to their id and back', function () {
    expect(PackageType::NAMES_IDS_MAP)->toHaveCount(7)
        ->and(DeliveryType::NAMES_IDS_MAP)->toHaveCount(7);

    foreach (PackageType::NAMES_IDS_MAP as $name => $id) {
        expect(PackageType::toIdOrNull($name))->toBe($id)
            ->and(PackageType::nameFromId($id))->toBe($name);
    }

    foreach (DeliveryType::NAMES_IDS_MAP as $name => $id) {
        expect(DeliveryType::toIdOrNull($name))->toBe($id)
            ->and(DeliveryType::nameFromId($id))->toBe($name);
    }
});

it('throws on an unknown package type id', function () {
    PackageType::nameFromId(99);
})->throws(InvalidArgumentException::class, "Unknown package type id '99'");

it('answers null for a name or id it does not know', function () {
    expect(PackageType::toIdOrNull('not-a-real-type'))->toBeNull()
        ->and(PackageType::toIdOrNull('DIGITAL_STAMP'))->toBeNull()
        ->and(PackageType::toIdOrNull(null))->toBeNull()
        ->and(PackageType::nameFromIdOrNull(99))->toBeNull()
        ->and(PackageType::nameFromIdOrNull(null))->toBeNull()
        ->and(DeliveryType::toIdOrNull('teleport'))->toBeNull()
        ->and(DeliveryType::nameFromIdOrNull(99))->toBeNull();
});
