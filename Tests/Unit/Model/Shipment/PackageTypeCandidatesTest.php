<?php

declare(strict_types=1);

use MyParcelNL\Magento\Model\Shipment\PackageType;
use MyParcelNL\Magento\Model\Shipment\PackageTypeCandidates;

it('starts empty', function () {
    expect(PackageTypeCandidates::none()->names())->toBe([])
        ->and(PackageTypeCandidates::none()->has(PackageType::MAILBOX_NAME))->toBeFalse();
});

it('does not mutate the instance it was asked to extend', function () {
    $empty = PackageTypeCandidates::none();
    $empty->with(PackageType::MAILBOX_NAME);

    expect($empty->names())->toBe([]);
});

it('keeps the names it was given', function () {
    $candidates = PackageTypeCandidates::none()
        ->with(PackageType::DIGITAL_STAMP_NAME)
        ->with(PackageType::MAILBOX_NAME);

    expect($candidates->has(PackageType::DIGITAL_STAMP_NAME))->toBeTrue()
        ->and($candidates->has(PackageType::MAILBOX_NAME))->toBeTrue()
        ->and($candidates->has(PackageType::PACKAGE_SMALL_NAME))->toBeFalse();
});

it('adds a name only once', function () {
    $candidates = PackageTypeCandidates::none()
        ->with(PackageType::MAILBOX_NAME)
        ->with(PackageType::MAILBOX_NAME);

    expect($candidates->names())->toBe([PackageType::MAILBOX_NAME]);
});

it('refuses package, which is the fallback rather than a candidate', function () {
    PackageTypeCandidates::none()->with(PackageType::PACKAGE_NAME);
})->throws(InvalidArgumentException::class, 'package is the fallback');
