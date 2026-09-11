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

it('resolves a name it knows and answers null for one it does not', function () {
    // isValidName()/isValidId() are gone: which types exist is the account's answer now, and a
    // local predicate shaped like an allow-list is what FR-000010 forbids. The resolvers survive,
    // because translating a name is not the same as deciding one is permitted.
    expect(PackageType::toIdOrNull('digital_stamp'))->toBe(PackageType::DIGITAL_STAMP)
        ->and(PackageType::toIdOrNull('DIGITAL_STAMP'))->toBeNull()
        ->and(PackageType::nameFromIdOrNull(PackageType::LETTER))->toBe(PackageType::LETTER_NAME)
        ->and(PackageType::nameFromIdOrNull(99))->toBeNull()
        ->and(DeliveryType::toIdOrNull('evening'))->toBe(DeliveryType::EVENING)
        ->and(DeliveryType::nameFromIdOrNull(DeliveryType::EXPRESS))->toBe(DeliveryType::EXPRESS_NAME)
        ->and(DeliveryType::nameFromIdOrNull(99))->toBeNull();
});

it('names every type the SDK can round-trip', function () {
    expect(PackageType::NAMES_IDS_MAP)->toHaveCount(7)
        ->and(DeliveryType::NAMES_IDS_MAP)->toHaveCount(7)
        ->and(PackageType::nameFromIdOrNull(PackageType::PALLET))->toBe(PackageType::PALLET_NAME)
        ->and(PackageType::nameFromIdOrNull(PackageType::ENVELOPE))->toBe(PackageType::ENVELOPE_NAME)
        ->and(DeliveryType::nameFromIdOrNull(DeliveryType::SAME_DAY))->toBe(DeliveryType::SAME_DAY_NAME)
        ->and(DeliveryType::nameFromIdOrNull(DeliveryType::EARLY_MORNING))->toBe(DeliveryType::EARLY_MORNING_NAME);
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
