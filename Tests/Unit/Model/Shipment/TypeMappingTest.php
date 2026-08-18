<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;

it('maps every package type name to its id and back', function () {
    foreach (PackageType::NAMES_IDS_MAP as $name => $id) {
        expect(PackageType::toId($name))->toBe($id)
            ->and(PackageType::nameFromId($id))->toBe($name);
    }
});

it('maps every delivery type name to its id and back', function () {
    foreach (DeliveryType::NAMES_IDS_MAP as $name => $id) {
        expect(DeliveryType::toId($name))->toBe($id)
            ->and(DeliveryType::nameFromId($id))->toBe($name);
    }
});

it('throws on an unknown package type name', function () {
    PackageType::toId('not-a-real-type');
})->throws(InvalidArgumentException::class, "Unknown package type 'not-a-real-type'");

it('throws on an unknown package type id', function () {
    PackageType::nameFromId(99);
})->throws(InvalidArgumentException::class, "Unknown package type id '99'");

it('throws on an unknown delivery type name', function () {
    DeliveryType::toId('teleport');
})->throws(InvalidArgumentException::class, "Unknown delivery type 'teleport'");

it('returns null instead of throwing on the forgiving path', function () {
    expect(PackageType::toIdOrNull('not-a-real-type'))->toBeNull()
        ->and(PackageType::toIdOrNull(null))->toBeNull()
        ->and(PackageType::toIdOrNull('mailbox'))->toBe(PackageType::MAILBOX)
        ->and(DeliveryType::toIdOrNull('teleport'))->toBeNull()
        ->and(DeliveryType::toIdOrNull(null))->toBeNull()
        ->and(DeliveryType::toIdOrNull('pickup'))->toBe(DeliveryType::PICKUP);
});

it('validates names and ids', function () {
    expect(PackageType::isValidName('digital_stamp'))->toBeTrue()
        ->and(PackageType::isValidName('DIGITAL_STAMP'))->toBeFalse()
        ->and(PackageType::isValidId(PackageType::LETTER))->toBeTrue()
        ->and(PackageType::isValidId(99))->toBeFalse()
        ->and(DeliveryType::isValidName('evening'))->toBeTrue()
        ->and(DeliveryType::isValidId(DeliveryType::EXPRESS))->toBeTrue()
        ->and(DeliveryType::isValidId(99))->toBeFalse();
});

it('names every type the SDK can round-trip', function () {
    expect(PackageType::NAMES_IDS_MAP)->toHaveCount(7)
        ->and(DeliveryType::NAMES_IDS_MAP)->toHaveCount(7)
        ->and(PackageType::isValidId(PackageType::PALLET))->toBeTrue()
        ->and(PackageType::isValidId(PackageType::ENVELOPE))->toBeTrue()
        ->and(DeliveryType::isValidId(DeliveryType::SAME_DAY))->toBeTrue()
        ->and(DeliveryType::isValidId(DeliveryType::EARLY_MORNING))->toBeTrue();
});

it('reads forgivingly and writes strictly', function () {
    expect(PackageType::nameFromIdOrNull(99))->toBeNull()
        ->and(PackageType::nameFromIdOrNull(null))->toBeNull()
        ->and(PackageType::nameFromIdOrNull(PackageType::PALLET))->toBe('pallet')
        ->and(DeliveryType::nameFromIdOrNull(99))->toBeNull()
        ->and(DeliveryType::nameFromIdOrNull(DeliveryType::EARLY_MORNING))->toBe('early_morning');

    expect(fn () => PackageType::toId('pallet_xl'))->toThrow(InvalidArgumentException::class);
    expect(fn () => PackageType::nameFromId(99))->toThrow(InvalidArgumentException::class);
});

it('resolves early morning instead of falling back to standard', function () {
    expect(DeliveryType::toIdOrNull('early_morning'))
        ->toBe(DeliveryType::EARLY_MORNING)
        ->not->toBe(DeliveryType::STANDARD);
});
