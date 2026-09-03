<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\DeliveryType;
use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\Type\DeliveryTypeValue;
use MyParcelNL\Magento\Model\Shipment\Type\PackageTypeValue;

/**
 * The DR-12 rule stated as tests: a stored type we do not recognise survives as itself, and the
 * three answers a caller can get — absent, unresolved, resolved — stay distinguishable.
 */
it('tells absent from unresolved from resolved', function () {
    expect(PackageTypeValue::fromStored(null)->isAbsent())->toBeTrue()
        ->and(PackageTypeValue::fromStored('')->isAbsent())->toBeTrue()
        ->and(PackageTypeValue::fromStored('pallet_xl')->isAbsent())->toBeFalse()
        ->and(PackageTypeValue::fromStored('pallet_xl')->isKnown())->toBeFalse()
        ->and(PackageTypeValue::fromStored(PackageType::MAILBOX_NAME)->isKnown())->toBeTrue();
});

it('answers nothing at all for an absent type', function () {
    $absent = PackageTypeValue::fromStored(null);

    expect($absent->name())->toBeNull()
        ->and($absent->id())->toBeNull()
        ->and($absent->label())->toBe('');
});

it('resolves a stored name', function () {
    $value = PackageTypeValue::fromStored(PackageType::PACKAGE_SMALL_NAME);

    expect($value->name())->toBe(PackageType::PACKAGE_SMALL_NAME)
        ->and($value->id())->toBe(PackageType::PACKAGE_SMALL)
        ->and($value->label())->toBe(PackageType::PACKAGE_SMALL_NAME)
        ->and($value->toApiValue())->toBe(PackageType::PACKAGE_SMALL);
});

it('reads a numeric value as an id, however it was stored', function ($stored) {
    $value = PackageTypeValue::fromStored($stored);

    expect($value->isKnown())->toBeTrue()
        ->and($value->name())->toBe(PackageType::MAILBOX_NAME)
        ->and($value->id())->toBe(PackageType::MAILBOX);
})->with([
    'as an int'            => [PackageType::MAILBOX],
    'as a numeric string'  => [(string) PackageType::MAILBOX],
]);

it('keeps an unresolved name as itself and refuses to send it', function () {
    $value = PackageTypeValue::fromStored('pallet_xl');

    expect($value->name())->toBe('pallet_xl')
        ->and($value->id())->toBeNull()
        ->and($value->label())->toBe('Package type pallet_xl');

    expect(fn () => $value->toApiValue())
        ->toThrow(InvalidArgumentException::class, 'pallet_xl');
});

/** An unknown id is passed on for the API to judge. An unknown name cannot be sent at all. */
it('passes an unresolved id through to the API', function () {
    $value = PackageTypeValue::fromStored(31);

    expect($value->isKnown())->toBeFalse()
        ->and($value->name())->toBe('31')
        ->and($value->id())->toBeNull()
        ->and($value->label())->toBe('Package type 31')
        ->and($value->toApiValue())->toBe(31);
});

it('has nothing to send when nothing was stored', function () {
    expect(fn () => DeliveryTypeValue::fromStored(null)->toApiValue())
        ->toThrow(InvalidArgumentException::class);
});

it('labels a delivery type as a delivery type', function () {
    expect(DeliveryTypeValue::fromStored('pallet_xl')->label())->toBe('Delivery type pallet_xl')
        ->and(DeliveryTypeValue::fromStored(DeliveryType::EARLY_MORNING_NAME)->id())
        ->toBe(DeliveryType::EARLY_MORNING);
});
