<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\TypeValue;

/**
 * The no-substitution rule stated as tests: a stored type we do not recognise survives as itself, and the
 * three answers a caller can get — absent, unresolved, resolved — stay distinguishable.
 *
 * Absent is name() === null; unresolved is a name() with no id(); resolved is both.
 */
it('tells absent from unresolved from resolved', function () {
    expect(TypeValue::fromStored(null, PackageType::class)->isAbsent())->toBeTrue()
        ->and(TypeValue::fromStored('', PackageType::class)->isAbsent())->toBeTrue()
        ->and(TypeValue::fromStored('pallet_xl', PackageType::class)->isAbsent())->toBeFalse()
        ->and(TypeValue::fromStored('pallet_xl', PackageType::class)->id())->toBeNull()
        ->and(TypeValue::fromStored(PackageType::MAILBOX_NAME, PackageType::class)->id())->toBe(PackageType::MAILBOX);
});

it('answers nothing at all for an absent type', function () {
    $absent = TypeValue::fromStored(null, PackageType::class);

    expect($absent->name())->toBeNull()
        ->and($absent->id())->toBeNull();
});

it('resolves a stored name', function () {
    $value = TypeValue::fromStored(PackageType::PACKAGE_SMALL_NAME, PackageType::class);

    expect($value->name())->toBe(PackageType::PACKAGE_SMALL_NAME)
        ->and($value->id())->toBe(PackageType::PACKAGE_SMALL)
        ->and($value->toApiValue())->toBe(PackageType::PACKAGE_SMALL);
});

it('reads a numeric value as an id, however it was stored', function ($stored) {
    $value = TypeValue::fromStored($stored, PackageType::class);

    expect($value->name())->toBe(PackageType::MAILBOX_NAME)
        ->and($value->id())->toBe(PackageType::MAILBOX);
})->with([
    'as an int'            => [PackageType::MAILBOX],
    'as a numeric string'  => [(string) PackageType::MAILBOX],
]);

it('keeps an unresolved name as itself and refuses to send it', function () {
    $value = TypeValue::fromStored('pallet_xl', PackageType::class);

    expect($value->name())->toBe('pallet_xl')
        ->and($value->id())->toBeNull();

    expect(fn () => $value->toApiValue())
        ->toThrow(InvalidArgumentException::class, 'pallet_xl');
});

it('passes an unresolved id through to the API', function () {
    $value = TypeValue::fromStored(31, PackageType::class);

    expect($value->name())->toBe('31')
        ->and($value->id())->toBeNull()
        ->and($value->toApiValue())->toBe(31);
});

it('has nothing to send when nothing was stored', function () {
    expect(fn () => TypeValue::fromStored(null, DeliveryType::class)->toApiValue())
        ->toThrow(InvalidArgumentException::class);
});

it('applies the same rules to a delivery type', function () {
    expect(TypeValue::fromStored('pallet_xl', DeliveryType::class)->name())->toBe('pallet_xl')
        ->and(TypeValue::fromStored('pallet_xl', DeliveryType::class)->id())->toBeNull()
        ->and(TypeValue::fromStored(DeliveryType::EARLY_MORNING_NAME, DeliveryType::class)->id())
        ->toBe(DeliveryType::EARLY_MORNING);

    expect(fn () => TypeValue::fromStored('pallet_xl', DeliveryType::class)->toApiValue())
        ->toThrow(InvalidArgumentException::class, 'pallet_xl');
});

it('refuses a stored value that is neither a string, an int nor null', function () {
    // name() would read a float as a name while toApiValue() re-tested it as an id, so one
    // instance answered both ways. fromStored()'s contract says string|int|null.
    expect(fn () => TypeValue::fromStored(1.5, PackageType::class))
        ->toThrow(InvalidArgumentException::class, 'must be a string, an int or null');
});
