<?php

declare(strict_types=1);

use MyParcelNL\Magento\Service\Export\LabelPositions;

/**
 * The type of the positions value decides the paper size: an array means those A4 positions, a
 * number means a starting position, anything else means A6. A comma string from an admin URL must
 * come back as an array. An absent value must stay null: a default number turns every A6 print into A4.
 */
it('answers the configured paper type with a whole A4 sheet, or nothing for A6', function ($paperType, $expected) {
    expect(makeLabelPositions($paperType)->configured())->toBe($expected);
})->with([
    'A4'             => ['A4', LabelPositions::A4_SHEET],
    'A6'             => ['A6', null],
    'not configured' => [null, null],
]);

it('fills the A4 sheet in the order the modal ticks its checkboxes', function () {
    // Same slots in the same order as _getLabelPosition() in mass-action.js, so a print started
    // without the modal lands the labels where one started with it would.
    expect(LabelPositions::A4_SHEET)->toBe([2, 4, 1, 3]);
});

it('turns the positions the url carries back into an array', function () {
    expect(makeLabelPositions()->decode('2,4,1'))->toBe([2, 4, 1]);
});

it('keeps a single chosen position an array rather than a starting number', function () {
    // As a number the SDK would read it as "start here and fill the sheet"; as an array it means
    // this position only, which is what the admin ticked.
    expect(makeLabelPositions()->decode('3'))->toBe([3]);
});

it('leaves an absent positions value null, so A6 stays A6', function () {
    expect(makeLabelPositions()->decode(null))->toBeNull()
        ->and(makeLabelPositions()->decode(''))->toBeNull();
});

it('round-trips a chosen sheet through the admin url', function () {
    $positions = makeLabelPositions();

    expect($positions->decode($positions->encode(LabelPositions::A4_SHEET)))
        ->toBe(LabelPositions::A4_SHEET);
});

it('encodes nothing at all rather than an empty parameter', function ($positions) {
    expect(makeLabelPositions()->encode($positions))->toBeNull();
})->with([
    'A6 sends null'      => [null],
    'no chosen position' => [[]],
    'a bare number'      => [1],
    'an empty string'    => [''],
]);
